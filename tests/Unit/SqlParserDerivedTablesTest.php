<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use QuerySentinel\Support\SqlParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for derived tables, virtual/window column aliases, and alias resolution.
 * Prevents regression where unqualified columns (e.g. rn from ROW_NUMBER() AS rn)
 * are incorrectly bound to the first physical table (products.rn).
 */
final class SqlParserDerivedTablesTest extends TestCase
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

    public function test_extract_derived_table_aliases_returns_subquery_aliases(): void
    {
        $aliases = SqlParser::extractDerivedTableAliases(self::COMPLEX_SQL);

        $this->assertContains('p', $aliases, 'Derived table alias p (FROM (SELECT...) p) should be found');
        $this->assertContains('i', $aliases, 'Derived table alias i (LEFT JOIN (SELECT...) i) should be found');
        $this->assertContains('x', $aliases, 'Nested derived table alias x should be found');
    }

    public function test_extract_virtual_column_aliases_returns_window_function_alias(): void
    {
        $columns = SqlParser::extractVirtualColumnAliases(self::COMPLEX_SQL);

        $this->assertContains('rn', $columns, 'ROW_NUMBER() ... AS rn should be detected as virtual column');
    }

    public function test_extract_table_aliases_includes_derived_aliases_with_null(): void
    {
        $aliasToTable = SqlParser::extractTableAliases(self::COMPLEX_SQL);

        $this->assertArrayHasKey('p', $aliasToTable);
        $this->assertNull($aliasToTable['p'], 'Derived table alias p must map to null (no physical table)');
        $this->assertArrayHasKey('i', $aliasToTable);
        $this->assertNull($aliasToTable['i'], 'Derived table alias i must map to null');
    }

    public function test_extract_table_aliases_physical_table_alias_maps_to_table(): void
    {
        $aliasToTable = SqlParser::extractTableAliases('SELECT u.id FROM users u JOIN orders o ON u.id = o.user_id');

        $this->assertSame('users', $aliasToTable['u'] ?? null);
        $this->assertSame('orders', $aliasToTable['o'] ?? null);
    }

    public function test_extract_virtual_column_aliases_multiple_as_in_select(): void
    {
        $sql = "SELECT id, name AS n, COUNT(*) AS cnt FROM t GROUP BY id";
        $columns = SqlParser::extractVirtualColumnAliases($sql);

        $this->assertContains('n', $columns);
        $this->assertContains('cnt', $columns);
    }

    public function test_derived_table_validation_sql_column_references_include_unqualified_rn(): void
    {
        $refs = SqlParser::extractColumnReferences(self::COMPLEX_SQL);

        $hasRn = false;
        foreach ($refs as $ref) {
            if ($ref['column'] === 'rn') {
                $hasRn = true;
                $this->assertNull($ref['table'], 'rn should be unqualified (from WHERE rn <= 3)');
                break;
            }
        }
        $this->assertTrue($hasRn, 'Column reference rn should be extracted from WHERE rn <= 3');
    }

    public function test_simple_derived_table_alias(): void
    {
        $sql = 'SELECT * FROM (SELECT id FROM users) u WHERE u.id = 1';
        $aliases = SqlParser::extractDerivedTableAliases($sql);

        $this->assertContains('u', $aliases);
        $aliasToTable = SqlParser::extractTableAliases($sql);
        $this->assertArrayHasKey('u', $aliasToTable);
        $this->assertNull($aliasToTable['u']);
    }

    /**
     * Alias inside a correlated subquery (e.g. FROM product_images pi) must be resolved to the physical table.
     * Prevents "Column 'pi.product_id' does not exist" / "Missing Table: pi".
     */
    public function test_extract_table_aliases_includes_subquery_aliases(): void
    {
        $sql = <<<'SQL'
SELECT p.*, i.url
FROM (SELECT * FROM products ORDER BY created_at DESC LIMIT 10) p
LEFT JOIN product_images i ON i.product_id = p.id
AND i.created_at >= (
    SELECT created_at FROM product_images pi
    WHERE pi.product_id = p.id
    ORDER BY pi.created_at DESC LIMIT 1 OFFSET 2
)
ORDER BY p.created_at DESC
SQL;

        $aliasToTable = SqlParser::extractTableAliases($sql);

        $this->assertSame('product_images', $aliasToTable['i'] ?? null, 'Top-level JOIN alias i => product_images');
        $this->assertSame('product_images', $aliasToTable['pi'] ?? null, 'Subquery alias pi => product_images (must be resolved so pi.product_id validates)');
        $this->assertArrayHasKey('p', $aliasToTable);
        $this->assertNull($aliasToTable['p'], 'Derived table p => null');
    }

    /**
     * ON clause containing a subquery (SELECT ...) must not produce false column refs like "SELECT" or "FROM".
     */
    public function test_join_on_with_subquery_does_not_capture_sql_keywords_as_columns(): void
    {
        $sql = "SELECT p.*, i.url FROM (SELECT * FROM products LIMIT 10) p "
            . "LEFT JOIN product_images i ON i.product_id = p.id "
            . "AND i.created_at >= (SELECT created_at FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.created_at DESC LIMIT 1 OFFSET 2) "
            . "ORDER BY p.created_at DESC";

        $refs = SqlParser::extractColumnReferences($sql);

        $columnNames = array_column($refs, 'column');
        $this->assertNotContains('SELECT', $columnNames, 'SELECT must not be captured as a column from subquery in ON');
        $this->assertNotContains('FROM', $columnNames, 'FROM must not be captured as a column');
        $this->assertNotContains('ORDER', $columnNames);
        $this->assertNotContains('BY', $columnNames);
        $this->assertNotContains('LIMIT', $columnNames);
        $this->assertNotContains('OFFSET', $columnNames);
    }
}
