<?php

declare(strict_types=1);

namespace QuerySentinel\Interception;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Support\QueryCapture;

/**
 * Passive query capture utility for middleware and interceptor.
 *
 * Unlike ProfilerAdapter (which wraps in transaction+rollback), this captor
 * is designed for production use: it captures queries passively via DB::listen
 * WITHOUT any transaction wrapping. The actual queries execute normally.
 *
 * Usage:
 *   $captor = new QueryCaptor();
 *   $captor->start();
 *   // ... business logic runs normally ...
 *   $captures = $captor->stop();
 */
final class QueryCaptor
{
    /** @var array<int, QueryCapture> */
    private array $captures = [];

    private bool $listening = false;

    /**
     * Start capturing queries via DB::listen.
     */
    public function start(): void
    {
        if ($this->listening) {
            return;
        }

        $this->captures = [];
        $this->listening = true;

        DB::listen(function ($query) {
            if (! $this->listening) {
                return;
            }

            $this->captures[] = new QueryCapture(
                sql: $query->sql,
                bindings: $query->bindings ?? [],
                timeMs: $query->time ?? 0.0,
                connection: $query->connectionName ?? null,
            );
        });
    }

    /**
     * Stop capturing and return all captured queries.
     *
     * @return array<int, QueryCapture>
     */
    public function stop(): array
    {
        $this->listening = false;

        $captures = $this->captures;
        $this->captures = [];

        return $captures;
    }

    /**
     * Check if currently capturing.
     */
    public function isListening(): bool
    {
        return $this->listening;
    }
}
