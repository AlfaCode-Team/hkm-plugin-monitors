<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** Delegates, but any read whose SQL contains `$needle` throws a PDOException. */
final class FailingQueryDatabase implements DatabasePort
{
    public function __construct(private readonly DatabasePort $inner, private readonly string $needle) {}

    private function check(string $sql): void
    {
        if (str_contains($sql, $this->needle)) {
            throw new \PDOException('simulated outage');
        }
    }

    public function query(string $sql, array $params = []): array { $this->check($sql); return $this->inner->query($sql, $params); }
    public function queryOne(string $sql, array $params = []): ?array { $this->check($sql); return $this->inner->queryOne($sql, $params); }
    public function execute(string $sql, array $params = []): int { return $this->inner->execute($sql, $params); }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns); }
    public function lastInsertId(?string $sequence = null): string { return $this->inner->lastInsertId($sequence); }
    public function beginTransaction(): void { $this->inner->beginTransaction(); }
    public function commit(): void { $this->inner->commit(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
}
