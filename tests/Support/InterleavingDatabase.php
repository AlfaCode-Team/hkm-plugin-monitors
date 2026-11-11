<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Delegates, and once — right AFTER a read whose SQL contains `$needle` returns
 * but BEFORE the caller sees it — runs `$between`. That is exactly the window a
 * concurrent process gets between another process's SELECT and its UPDATE.
 */
final class InterleavingDatabase implements DatabasePort
{
    private bool $fired = false;

    /** @param callable(): void $between */
    public function __construct(
        private readonly DatabasePort $inner,
        private readonly string $needle,
        private readonly mixed $between,
    ) {}

    private function after(string $sql): void
    {
        if (!$this->fired && str_contains($sql, $this->needle)) {
            $this->fired = true;
            ($this->between)();
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $rows = $this->inner->query($sql, $params);
        $this->after($sql);

        return $rows;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $row = $this->inner->queryOne($sql, $params);
        $this->after($sql);

        return $row;
    }
    public function execute(string $sql, array $params = []): int { return $this->inner->execute($sql, $params); }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns); }
    public function lastInsertId(?string $sequence = null): string { return $this->inner->lastInsertId($sequence); }
    public function beginTransaction(): void { $this->inner->beginTransaction(); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
}
