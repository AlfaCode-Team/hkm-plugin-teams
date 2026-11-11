<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** Delegates to a real database but throws a PDOException from upsert() on demand. */
final class FailingDatabase implements DatabasePort
{
    public bool $failUpsert = false;

    public function __construct(private readonly DatabasePort $inner) {}

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->inner->queryOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        if ($this->failUpsert) {
            throw new \PDOException('disk full');
        }

        return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $this->inner->lastInsertId($sequence);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
