<?php

declare(strict_types=1);

namespace QuerySentinel\Scanner;

use QuerySentinel\Attributes\DiagnoseQuery;
use Symfony\Component\Finder\Finder;

/**
 * Recursively scans configured directories for methods annotated with
 * #[DiagnoseQuery] and returns a list of ScannedMethod DTOs.
 *
 * Uses Symfony Finder to locate PHP files, checks file contents for
 * the attribute string before autoloading, then uses Reflection to
 * discover annotated methods.
 *
 * Safety: Files that don't mention DiagnoseQuery are never autoloaded,
 * preventing parse errors in unrelated files from crashing the scan.
 */
final class AttributeScanner
{
    /**
     * @param  array<int, string>  $scanPaths  Absolute paths to scan
     */
    public function __construct(
        private readonly array $scanPaths,
    ) {}

    /**
     * Scan configured paths and return all discovered methods.
     *
     * @return ScannedMethod[]
     */
    public function scan(): array
    {
        $methods = [];

        foreach ($this->scanPaths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $finder = new Finder;
            $finder->files()
                ->name('*.php')
                ->in($path)
                ->sortByName()
                ->contains('DiagnoseQuery');

            foreach ($finder as $file) {
                $filePath = $file->getRealPath();
                if ($filePath === false) {
                    continue;
                }

                try {
                    $className = $this->extractClassName($filePath);
                    if ($className === null) {
                        continue;
                    }

                    $discovered = $this->scanClass($className, $filePath);
                    array_push($methods, ...$discovered);
                } catch (\Throwable) {
                    // Skip files with syntax errors or unresolvable dependencies
                    continue;
                }
            }
        }

        return $methods;
    }

    /**
     * Scan a single class for #[DiagnoseQuery] methods.
     *
     * @param  class-string  $className
     * @return ScannedMethod[]
     */
    private function scanClass(string $className, string $filePath): array
    {
        $reflection = new \ReflectionClass($className);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return [];
        }

        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            // Only scan methods declared in THIS class (not inherited)
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $attributes = $method->getAttributes(DiagnoseQuery::class);
            if (empty($attributes)) {
                continue;
            }

            /** @var DiagnoseQuery $attribute */
            $attribute = $attributes[0]->newInstance();

            $methods[] = new ScannedMethod(
                className: $className,
                methodName: $method->getName(),
                filePath: $filePath,
                lineNumber: $method->getStartLine() ?: 0,
                attribute: $attribute,
            );
        }

        return $methods;
    }

    /**
     * Extract the fully-qualified class name from a PHP file.
     *
     * Uses token-based parsing to safely extract namespace and class name
     * without triggering autoloading. Only calls class_exists() at the end
     * to verify the class is autoloadable.
     *
     * @return class-string|null
     */
    private function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        $class = '';

        try {
            $tokens = token_get_all($contents, TOKEN_PARSE);
        } catch (\ParseError) {
            // File has syntax errors — skip it
            return null;
        }

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            // Extract namespace
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespaceParts = [];
                $i++;
                while ($i < $count) {
                    if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespaceParts[] = $tokens[$i][1];
                    } elseif (! is_array($tokens[$i]) && $tokens[$i] === ';') {
                        break;
                    } elseif (! is_array($tokens[$i]) && $tokens[$i] === '{') {
                        break;
                    }
                    $i++;
                }
                $namespace = implode('', $namespaceParts);
            }

            // Extract first class name
            if ($tokens[$i][0] === T_CLASS && $class === '') {
                // Skip anonymous classes: look back for tokens like `new`
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW) {
                    continue;
                }

                // Next non-whitespace token is the class name
                $i++;
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }
                if ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $class = $tokens[$i][1];
                }
            }
        }

        if ($class === '') {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace.'\\'.$class : $class;

        // Autoload only this specific class — wrapped in error suppression
        // to survive parse errors in the file or its dependencies.
        $loaded = false;
        set_error_handler(static fn () => true);
        try {
            $loaded = class_exists($fqcn);
        } catch (\Throwable) {
            // Parse errors, missing dependencies, etc.
        } finally {
            restore_error_handler();
        }

        if (! $loaded) {
            return null;
        }

        /** @var class-string $fqcn */
        return $fqcn;
    }
}
