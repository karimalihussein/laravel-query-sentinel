<?php

declare(strict_types=1);

namespace QuerySentinel\Exceptions;

final class UnsafeQueryException extends \RuntimeException
{
    public static function destructiveQuery(string $keyword): self
    {
        return new self(sprintf(
            'Destructive SQL operation detected: %s. Only SELECT and EXPLAIN queries are allowed.',
            strtoupper($keyword),
        ));
    }

    public static function notAllowed(string $sql): self
    {
        $preview = mb_substr(trim($sql), 0, 80);

        return new self(sprintf(
            'Query not allowed. Only SELECT and EXPLAIN statements are permitted. Got: %s',
            $preview,
        ));
    }

    public static function emptyQuery(): self
    {
        return new self('Empty SQL query provided.');
    }
}
