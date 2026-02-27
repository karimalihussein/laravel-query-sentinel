<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Scanner\AttributeScanner;
use QuerySentinel\Scanner\ScannedMethod;
use QuerySentinel\Tests\Unit\Scanner\Fixtures\SampleQueryService;

final class AttributeScannerTest extends TestCase
{
    public function test_scan_returns_empty_for_nonexistent_path(): void
    {
        $scanner = new AttributeScanner(['/nonexistent/path/that/does/not/exist']);

        $this->assertSame([], $scanner->scan());
    }

    public function test_scan_returns_empty_for_empty_paths(): void
    {
        $scanner = new AttributeScanner([]);

        $this->assertSame([], $scanner->scan());
    }

    public function test_scan_discovers_annotated_methods(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $this->assertNotEmpty($methods);
        $this->assertContainsOnlyInstancesOf(ScannedMethod::class, $methods);
    }

    public function test_scan_finds_correct_number_of_annotated_methods(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        // SampleQueryService has 3 annotated methods, AbstractService is skipped
        $sampleMethods = array_filter(
            $methods,
            fn (ScannedMethod $m) => $m->className === SampleQueryService::class,
        );

        $this->assertCount(3, $sampleMethods);
    }

    public function test_scan_skips_methods_without_attribute(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $methodNames = array_map(fn (ScannedMethod $m) => $m->methodName, $methods);

        $this->assertNotContains('nonAnnotatedMethod', $methodNames);
        $this->assertNotContains('anotherRegularMethod', $methodNames);
    }

    public function test_scan_skips_abstract_classes(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $classNames = array_map(fn (ScannedMethod $m) => $m->className, $methods);

        $this->assertNotContains(
            'QuerySentinel\\Tests\\Unit\\Scanner\\Fixtures\\AbstractService',
            $classNames,
        );
    }

    public function test_scanned_methods_have_correct_file_paths(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        foreach ($methods as $method) {
            $this->assertFileExists($method->filePath);
            $this->assertGreaterThan(0, $method->lineNumber);
        }
    }

    public function test_scanned_methods_have_correct_labels(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $labeled = array_filter(
            $methods,
            fn (ScannedMethod $m) => $m->attribute->label === 'Active users query',
        );

        $this->assertCount(1, $labeled);

        $first = reset($labeled);
        $this->assertSame('activeUsersQuery', $first->methodName);
    }

    public function test_scanned_method_with_description(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $withDesc = array_filter(
            $methods,
            fn (ScannedMethod $m) => $m->attribute->description !== '',
        );

        $this->assertNotEmpty($withDesc);

        $first = reset($withDesc);
        $this->assertSame('Full-text search across leads', $first->attribute->description);
    }

    public function test_display_label_uses_attribute_label_when_set(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $labeled = array_filter(
            $methods,
            fn (ScannedMethod $m) => $m->attribute->label !== '',
        );

        foreach ($labeled as $method) {
            $this->assertSame($method->attribute->label, $method->displayLabel());
        }
    }

    public function test_display_label_falls_back_to_class_method(): void
    {
        $scanner = new AttributeScanner([__DIR__.'/Fixtures']);
        $methods = $scanner->scan();

        $unlabeled = array_filter(
            $methods,
            fn (ScannedMethod $m) => $m->attribute->label === '',
        );

        foreach ($unlabeled as $method) {
            $this->assertStringContains('::', $method->displayLabel());
        }
    }

    /**
     * PHPUnit 10 compatible string contains assertion.
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'.",
        );
    }
}
