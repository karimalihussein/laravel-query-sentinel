<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * File-based JSON storage for regression baselines.
 *
 * Stores analysis snapshots per query hash with no database dependency.
 * Each query hash gets its own JSON file containing a chronologically
 * ordered array of snapshot entries.
 */
final class BaselineStore
{
    private string $storagePath;

    private int $maxSnapshots;

    public function __construct(string $storagePath, int $maxSnapshots = 50)
    {
        $this->storagePath = rtrim($storagePath, '/');
        $this->maxSnapshots = $maxSnapshots;
    }

    /**
     * Save a snapshot for a query hash.
     *
     * Appends to existing history and trims to maxSnapshots entries.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function save(string $queryHash, array $snapshot): void
    {
        $this->ensureDirectoryExists();

        $filePath = $this->filePath($queryHash);
        $data = $this->readFile($filePath);

        $data['snapshots'][] = $snapshot;

        // Trim to max entries, keeping most recent
        if (count($data['snapshots']) > $this->maxSnapshots) {
            $data['snapshots'] = array_values(
                array_slice($data['snapshots'], -$this->maxSnapshots)
            );
        }

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Load the most recent snapshot for a query hash.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $queryHash): ?array
    {
        $filePath = $this->filePath($queryHash);
        $data = $this->readFile($filePath);

        if (empty($data['snapshots'])) {
            return null;
        }

        /** @var array<string, mixed> */
        $last = end($data['snapshots']);

        return $last;
    }

    /**
     * Load full history for a query hash.
     *
     * Returns the last N snapshots, most recent last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string $queryHash, int $limit = 10): array
    {
        $filePath = $this->filePath($queryHash);
        $data = $this->readFile($filePath);

        if (empty($data['snapshots'])) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $snapshots */
        $snapshots = $data['snapshots'];

        if (count($snapshots) <= $limit) {
            return array_values($snapshots);
        }

        return array_values(array_slice($snapshots, -$limit));
    }

    /**
     * Prune snapshots older than maxAge days.
     *
     * Iterates all JSON files and removes entries with timestamps older
     * than the cutoff. Deletes files that become empty after pruning.
     *
     * @return int Count of pruned snapshot entries
     */
    public function prune(int $maxAgeDays = 30): int
    {
        $pruned = 0;

        if (! is_dir($this->storagePath)) {
            return 0;
        }

        $cutoff = date('c', time() - ($maxAgeDays * 86400));
        $files = glob($this->storagePath.'/*.json');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $filePath) {
            $data = $this->readFile($filePath);

            if (empty($data['snapshots'])) {
                continue;
            }

            $originalCount = count($data['snapshots']);

            $data['snapshots'] = array_values(array_filter(
                $data['snapshots'],
                static function (mixed $snapshot) use ($cutoff): bool {
                    if (! is_array($snapshot)) {
                        return false;
                    }
                    $timestamp = $snapshot['timestamp'] ?? null;
                    if (! is_string($timestamp)) {
                        return false;
                    }

                    return $timestamp >= $cutoff;
                }
            ));

            $newCount = count($data['snapshots']);
            $pruned += ($originalCount - $newCount);

            if ($newCount === 0) {
                unlink($filePath);
            } elseif ($newCount < $originalCount) {
                file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
        }

        return $pruned;
    }

    private function filePath(string $queryHash): string
    {
        return $this->storagePath.'/'.$queryHash.'.json';
    }

    /**
     * @return array{snapshots: array<int, array<string, mixed>>}
     */
    private function readFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return ['snapshots' => []];
        }

        $contents = file_get_contents($filePath);

        if ($contents === false || $contents === '') {
            return ['snapshots' => []];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['snapshots']) || ! is_array($decoded['snapshots'])) {
            return ['snapshots' => []];
        }

        /** @var array{snapshots: array<int, array<string, mixed>>} $decoded */
        return $decoded;
    }

    private function ensureDirectoryExists(): void
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }
}
