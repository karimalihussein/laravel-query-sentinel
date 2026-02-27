<?php

declare(strict_types=1);

namespace QuerySentinel\Scanner;

use QuerySentinel\Attributes\DiagnoseQuery;

/**
 * Immutable DTO representing a discovered #[DiagnoseQuery] method.
 *
 * Produced by AttributeScanner, consumed by the query:scan command
 * to present the interactive selection list.
 */
final readonly class ScannedMethod
{
    /**
     * @param  class-string  $className
     */
    public function __construct(
        public string $className,
        public string $methodName,
        public string $filePath,
        public int $lineNumber,
        public DiagnoseQuery $attribute,
    ) {}

    /**
     * Display label: attribute label if set, otherwise ClassName::method.
     */
    public function displayLabel(): string
    {
        if ($this->attribute->label !== '') {
            return $this->attribute->label;
        }

        return $this->shortClassName().'::'.$this->methodName;
    }

    /**
     * Short class name (without namespace).
     */
    public function shortClassName(): string
    {
        $parts = explode('\\', $this->className);

        return (string) end($parts);
    }

    /**
     * Fully qualified identifier.
     */
    public function qualifiedName(): string
    {
        return $this->className.'::'.$this->methodName;
    }

    /**
     * Whether the method has required parameters (unsupported for scanning).
     */
    public function hasRequiredParameters(): bool
    {
        try {
            $reflection = new \ReflectionMethod($this->className, $this->methodName);

            return $reflection->getNumberOfRequiredParameters() > 0;
        } catch (\ReflectionException) {
            return true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->className,
            'method' => $this->methodName,
            'file' => $this->filePath,
            'line' => $this->lineNumber,
            'label' => $this->attribute->label,
            'description' => $this->attribute->description,
        ];
    }
}
