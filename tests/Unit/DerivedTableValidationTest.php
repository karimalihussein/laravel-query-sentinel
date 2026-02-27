<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use Illuminate\Database\Connection;
use QuerySentinel\Contracts\SchemaIntrospector;
use QuerySentinel\Support\SchemaIntrospectors\PermissiveSchemaIntrospector;
use QuerySentinel\Tests\TestCase;
use QuerySentinel\Validation\SchemaValidator;
use QuerySentinel\Validation\ValidationPipeline;

/**
 * Validation passes for SQL with derived tables and window-function aliases (e.g. ROW_NUMBER() AS rn).
 * Without the fix, unqualified 'rn' was incorrectly bound to the first physical table (products.rn) and failed.
 */
final class DerivedTableValidationTest extends TestCase
{
    private const COMPLEX_SQL = <<<'SQL'
SELECT p.*, i.url
FROM (
    SELECT *
    FROM products
    ORDER BY created_at DESC
    LIMIT 10
) p
LEFT JOIN (
    SELECT *
    FROM (
        SELECT *,
               ROW_NUMBER() OVER (
                   PARTITION BY product_id
                   ORDER BY created_at DESC
               ) AS rn
        FROM product_images
    ) x
    WHERE rn <= 3
) i ON i.product_id = p.id
ORDER BY p.created_at DESC;
SQL;

    public function test_validation_passes_for_derived_tables_and_window_alias_with_permissive_introspector(): void
    {
        $introspector = new PermissiveSchemaIntrospector;
        $pipeline = new ValidationPipeline(null, $introspector, null);

        $result = $pipeline->validate(self::COMPLEX_SQL);

        $this->assertTrue($result->isValid(), 'Validation should pass: derived aliases (p, i) and virtual column (rn) must not be validated against physical schema.');
    }

    /**
     * SQL where outer FROM is physical (products) so unqualified rn would resolve to products without the fix.
     * Introspector reports products/product_images exist but column 'rn' does not exist on products.
     * Proves that the validator skips checking rn (virtual) and refs to derived alias i.
     */
    private const SQL_PHYSICAL_FROM_WITH_RN = <<<'SQL'
SELECT * FROM products p
LEFT JOIN (
    SELECT *, ROW_NUMBER() OVER (ORDER BY id) AS rn
    FROM product_images
) i ON i.product_id = p.id
WHERE rn <= 3
SQL;

    public function test_validation_passes_when_rn_is_skipped_as_virtual_even_if_not_in_physical_schema(): void
    {
        $introspector = new class implements SchemaIntrospector
        {
            public function tableExists(Connection $conn, string $table): ?object
            {
                return in_array($table, ['products', 'product_images'], true) ? (object) ['TABLE_NAME' => $table] : null;
            }

            public function listTables(Connection $conn): array
            {
                return [];
            }

            public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object
            {
                if ($table === 'products' && $column === 'rn') {
                    return null;
                }

                return (object) ['COLUMN_NAME' => $column];
            }

            public function listColumns(Connection $conn, string $dbName, string $table): array
            {
                return [];
            }
        };

        $pipeline = new ValidationPipeline(null, $introspector, null);
        $result = $pipeline->validate(self::SQL_PHYSICAL_FROM_WITH_RN);

        $this->assertTrue($result->isValid(), 'Validator must skip virtual column rn; must not fail with "Column products.rn does not exist".');
    }

    public function test_schema_validator_accepts_derived_alias_map_with_nulls(): void
    {
        $introspector = new PermissiveSchemaIntrospector;
        $validator = new SchemaValidator($introspector, null);

        $aliasToTable = [
            'p' => null,
            'i' => null,
            'x' => null,
        ];

        $result = $validator->validateColumns(self::COMPLEX_SQL, $aliasToTable);

        $this->assertTrue($result->isValid());
    }
}
