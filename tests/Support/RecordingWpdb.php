<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

final class RecordingWpdb extends \wpdb
{
    public string $lastPrepareSql = '';

    /** @var list<mixed> */
    public array $lastPrepareArgs = [];

    public string $lastQuery = '';

    public function prepare(string $sql, mixed ...$args): string
    {
        $this->lastPrepareSql = $sql;
        $this->lastPrepareArgs = array_values($args);

        return $sql;
    }

    public function query(string $sql): mixed
    {
        $this->lastQuery = $sql;

        return 1;
    }
}
