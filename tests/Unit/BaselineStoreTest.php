<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\BaselineStore;

final class BaselineStoreTest extends TestCase
{
    private string $tempDir;

    private BaselineStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/qs_baseline_test_' . uniqid();
        $this->store = new BaselineStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // 1. Save and load a snapshot
    // ---------------------------------------------------------------

    public function test_save_and_load_snapshot(): void
    {
        $snapshot = [
            'query_hash' => 'abc123',
            'timestamp' => date('c'),
            'composite_score' => 85.0,
            'grade' => 'B',
        ];

        $this->store->save('abc123', $snapshot);
        $loaded = $this->store->load('abc123');

        $this->assertNotNull($loaded);
        $this->assertSame('abc123', $loaded['query_hash']);
        $this->assertEquals(85.0, $loaded['composite_score']);
        $this->assertSame('B', $loaded['grade']);
    }

    // ---------------------------------------------------------------
    // 2. Load returns null for unknown hash
    // ---------------------------------------------------------------

    public function test_load_returns_null_for_unknown_hash(): void
    {
        $result = $this->store->load('nonexistent_hash');

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // 3. History returns snapshots in order
    // ---------------------------------------------------------------

    public function test_history_returns_snapshots_in_order(): void
    {
        $this->store->save('hash1', ['timestamp' => '2025-01-01T00:00:00+00:00', 'value' => 1]);
        $this->store->save('hash1', ['timestamp' => '2025-01-02T00:00:00+00:00', 'value' => 2]);
        $this->store->save('hash1', ['timestamp' => '2025-01-03T00:00:00+00:00', 'value' => 3]);

        $history = $this->store->history('hash1');

        $this->assertCount(3, $history);
        $this->assertSame(1, $history[0]['value']);
        $this->assertSame(2, $history[1]['value']);
        $this->assertSame(3, $history[2]['value']);
    }

    // ---------------------------------------------------------------
    // 4. History with limit
    // ---------------------------------------------------------------

    public function test_history_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->save('hash1', ['timestamp' => date('c'), 'value' => $i]);
        }

        $history = $this->store->history('hash1', 3);

        $this->assertCount(3, $history);
        // Should return last 3 entries: 3, 4, 5
        $this->assertSame(3, $history[0]['value']);
        $this->assertSame(4, $history[1]['value']);
        $this->assertSame(5, $history[2]['value']);
    }

    // ---------------------------------------------------------------
    // 5. Save appends to existing history
    // ---------------------------------------------------------------

    public function test_save_appends_to_existing_history(): void
    {
        $this->store->save('hash1', ['value' => 'first', 'timestamp' => date('c')]);
        $this->store->save('hash1', ['value' => 'second', 'timestamp' => date('c')]);

        $history = $this->store->history('hash1');

        $this->assertCount(2, $history);
        $this->assertSame('first', $history[0]['value']);
        $this->assertSame('second', $history[1]['value']);
    }

    // ---------------------------------------------------------------
    // 6. Prune removes old entries
    // ---------------------------------------------------------------

    public function test_prune_removes_old_entries(): void
    {
        $oldTimestamp = date('c', strtotime('-60 days'));
        $recentTimestamp = date('c');

        $this->store->save('hash1', ['timestamp' => $oldTimestamp, 'value' => 'old']);
        $this->store->save('hash1', ['timestamp' => $recentTimestamp, 'value' => 'recent']);

        $pruned = $this->store->prune(30);

        $this->assertSame(1, $pruned);

        $history = $this->store->history('hash1');
        $this->assertCount(1, $history);
        $this->assertSame('recent', $history[0]['value']);
    }

    // ---------------------------------------------------------------
    // 7. Prune returns count of removed entries
    // ---------------------------------------------------------------

    public function test_prune_returns_count_of_removed_entries(): void
    {
        $oldTimestamp = date('c', strtotime('-90 days'));

        $this->store->save('hash1', ['timestamp' => $oldTimestamp, 'value' => 1]);
        $this->store->save('hash1', ['timestamp' => $oldTimestamp, 'value' => 2]);
        $this->store->save('hash2', ['timestamp' => $oldTimestamp, 'value' => 3]);

        $pruned = $this->store->prune(30);

        $this->assertSame(3, $pruned);
    }

    // ---------------------------------------------------------------
    // 8. Prune deletes empty files
    // ---------------------------------------------------------------

    public function test_prune_deletes_empty_files(): void
    {
        $oldTimestamp = date('c', strtotime('-60 days'));
        $this->store->save('hash1', ['timestamp' => $oldTimestamp, 'value' => 'old']);

        $filePath = $this->tempDir . '/hash1.json';
        $this->assertFileExists($filePath);

        $this->store->prune(30);

        $this->assertFileDoesNotExist($filePath);
    }

    // ---------------------------------------------------------------
    // 9. Missing storage directory is created automatically
    // ---------------------------------------------------------------

    public function test_missing_storage_directory_created_automatically(): void
    {
        $nestedPath = $this->tempDir . '/nested/deep/storage';
        $store = new BaselineStore($nestedPath);

        $store->save('hash1', ['timestamp' => date('c'), 'value' => 'test']);

        $this->assertDirectoryExists($nestedPath);
        $this->assertNotNull($store->load('hash1'));
    }

    // ---------------------------------------------------------------
    // 10. Multiple query hashes stored independently
    // ---------------------------------------------------------------

    public function test_multiple_query_hashes_stored_independently(): void
    {
        $this->store->save('hash_a', ['timestamp' => date('c'), 'value' => 'query_a']);
        $this->store->save('hash_b', ['timestamp' => date('c'), 'value' => 'query_b']);

        $loadedA = $this->store->load('hash_a');
        $loadedB = $this->store->load('hash_b');

        $this->assertNotNull($loadedA);
        $this->assertNotNull($loadedB);
        $this->assertSame('query_a', $loadedA['value']);
        $this->assertSame('query_b', $loadedB['value']);

        // Verify separate files exist
        $this->assertFileExists($this->tempDir . '/hash_a.json');
        $this->assertFileExists($this->tempDir . '/hash_b.json');
    }

    // ---------------------------------------------------------------
    // 11. History returns empty array for unknown hash
    // ---------------------------------------------------------------

    public function test_history_returns_empty_for_unknown_hash(): void
    {
        $history = $this->store->history('nonexistent');

        $this->assertSame([], $history);
    }

    // ---------------------------------------------------------------
    // 12. Save trims to max snapshots
    // ---------------------------------------------------------------

    public function test_save_trims_to_max_snapshots(): void
    {
        $store = new BaselineStore($this->tempDir, 5);

        for ($i = 1; $i <= 10; $i++) {
            $store->save('hash1', ['timestamp' => date('c'), 'value' => $i]);
        }

        $history = $store->history('hash1', 100);

        $this->assertCount(5, $history);
        // Should keep the last 5: values 6, 7, 8, 9, 10
        $this->assertSame(6, $history[0]['value']);
        $this->assertSame(10, $history[4]['value']);
    }

    // ---------------------------------------------------------------
    // 13. Prune on non-existent directory returns zero
    // ---------------------------------------------------------------

    public function test_prune_on_nonexistent_directory_returns_zero(): void
    {
        $store = new BaselineStore('/tmp/nonexistent_dir_' . uniqid());

        $pruned = $store->prune(30);

        $this->assertSame(0, $pruned);
    }

    // ---------------------------------------------------------------
    // 14. Prune keeps recent entries across multiple files
    // ---------------------------------------------------------------

    public function test_prune_keeps_recent_entries_across_files(): void
    {
        $oldTimestamp = date('c', strtotime('-60 days'));
        $recentTimestamp = date('c');

        $this->store->save('hash1', ['timestamp' => $oldTimestamp, 'value' => 'old1']);
        $this->store->save('hash1', ['timestamp' => $recentTimestamp, 'value' => 'recent1']);
        $this->store->save('hash2', ['timestamp' => $oldTimestamp, 'value' => 'old2']);
        $this->store->save('hash2', ['timestamp' => $recentTimestamp, 'value' => 'recent2']);

        $pruned = $this->store->prune(30);

        $this->assertSame(2, $pruned);
        $this->assertSame('recent1', $this->store->load('hash1')['value']);
        $this->assertSame('recent2', $this->store->load('hash2')['value']);
    }

    // ---------------------------------------------------------------
    // Helper: recursively remove temp directory
    // ---------------------------------------------------------------

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
