<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Attributes\DiagnoseQuery;
use QuerySentinel\Scanner\ScannedMethod;

final class ScannedMethodTest extends TestCase
{
    public function test_display_label_returns_attribute_label(): void
    {
        $method = $this->createMethod(label: 'Active users');

        $this->assertSame('Active users', $method->displayLabel());
    }

    public function test_display_label_falls_back_to_class_method(): void
    {
        $method = $this->createMethod();

        $this->assertSame('UserService::activeUsersQuery', $method->displayLabel());
    }

    public function test_qualified_name(): void
    {
        $method = $this->createMethod();

        $this->assertSame('App\\Services\\UserService::activeUsersQuery', $method->qualifiedName());
    }

    public function test_short_class_name(): void
    {
        $method = $this->createMethod();

        $this->assertSame('UserService', $method->shortClassName());
    }

    public function test_short_class_name_without_namespace(): void
    {
        $method = new ScannedMethod(
            className: 'SimpleClass',
            methodName: 'query',
            filePath: '/app/SimpleClass.php',
            lineNumber: 10,
            attribute: new DiagnoseQuery,
        );

        $this->assertSame('SimpleClass', $method->shortClassName());
    }

    public function test_to_array(): void
    {
        $method = $this->createMethod(label: 'Active users', description: 'Gets active users');

        $array = $method->toArray();

        $this->assertSame('App\\Services\\UserService', $array['class']);
        $this->assertSame('activeUsersQuery', $array['method']);
        $this->assertSame('/app/Services/UserService.php', $array['file']);
        $this->assertSame(42, $array['line']);
        $this->assertSame('Active users', $array['label']);
        $this->assertSame('Gets active users', $array['description']);
    }

    public function test_to_array_with_defaults(): void
    {
        $method = $this->createMethod();
        $array = $method->toArray();

        $this->assertSame('', $array['label']);
        $this->assertSame('', $array['description']);
    }

    public function test_has_required_parameters_with_no_params(): void
    {
        $method = new ScannedMethod(
            className: ScannedMethodTestFixture::class,
            methodName: 'noParams',
            filePath: __FILE__,
            lineNumber: 1,
            attribute: new DiagnoseQuery,
        );

        $this->assertFalse($method->hasRequiredParameters());
    }

    public function test_has_required_parameters_with_optional_params(): void
    {
        $method = new ScannedMethod(
            className: ScannedMethodTestFixture::class,
            methodName: 'optionalParams',
            filePath: __FILE__,
            lineNumber: 1,
            attribute: new DiagnoseQuery,
        );

        $this->assertFalse($method->hasRequiredParameters());
    }

    public function test_has_required_parameters_with_required_params(): void
    {
        $method = new ScannedMethod(
            className: ScannedMethodTestFixture::class,
            methodName: 'requiredParams',
            filePath: __FILE__,
            lineNumber: 1,
            attribute: new DiagnoseQuery,
        );

        $this->assertTrue($method->hasRequiredParameters());
    }

    public function test_has_required_parameters_with_nonexistent_class(): void
    {
        $method = new ScannedMethod(
            className: 'NonExistent\\ClassName',
            methodName: 'query',
            filePath: '/fake.php',
            lineNumber: 1,
            attribute: new DiagnoseQuery,
        );

        $this->assertTrue($method->hasRequiredParameters());
    }

    private function createMethod(string $label = '', string $description = ''): ScannedMethod
    {
        return new ScannedMethod(
            className: 'App\\Services\\UserService',
            methodName: 'activeUsersQuery',
            filePath: '/app/Services/UserService.php',
            lineNumber: 42,
            attribute: new DiagnoseQuery(label: $label, description: $description),
        );
    }
}

/**
 * @internal Test fixture — not part of the public API.
 */
class ScannedMethodTestFixture
{
    public function noParams(): void {}

    public function optionalParams(string $filter = ''): void {}

    public function requiredParams(string $filter): void {}
}
