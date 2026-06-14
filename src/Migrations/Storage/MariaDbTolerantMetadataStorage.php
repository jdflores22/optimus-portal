<?php

declare(strict_types=1);

namespace App\Migrations\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Exception\MetadataStorageError;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Query\Query;
use Doctrine\Migrations\Version\AlphabeticalComparator;
use Doctrine\Migrations\Version\Comparator as MigrationsComparator;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;

/**
 * MariaDB + DBAL 4 can report a false-positive schema diff on executed_at
 * (default 'NULL' string vs null, empty platform options). This storage
 * ignores that specific drift so migration commands remain usable.
 */
final class MariaDbTolerantMetadataStorage implements MetadataStorage
{
    private bool $schemaUpToDate = false;

    private readonly MigrationsComparator $comparator;

    public function __construct(
        private readonly Connection $connection,
        private readonly TableMetadataStorageConfiguration $configuration = new TableMetadataStorageConfiguration(),
        ?MigrationsComparator $comparator = null,
    ) {
        $this->comparator = $comparator ?? new AlphabeticalComparator();
    }

    public function getExecutedMigrations(): ExecutedMigrationsList
    {
        $this->assertSchemaReady();

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s', $this->configuration->getTableName())
        );

        $migrations = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $version = new Version($row[strtolower($this->configuration->getVersionColumnName())]);

            $executedAtRaw = $row[strtolower($this->configuration->getExecutedAtColumnName())] ?? '';
            $executedAt = $executedAtRaw !== ''
                ? DateTimeImmutable::createFromFormat(
                    $this->connection->getDatabasePlatform()->getDateTimeFormatString(),
                    $executedAtRaw,
                )
                : null;

            $executionTimeRaw = $row[strtolower($this->configuration->getExecutionTimeColumnName())] ?? null;
            $executionTime = $executionTimeRaw !== null
                ? (float) $executionTimeRaw / 1000
                : null;

            $migrations[(string) $version] = new ExecutedMigration(
                $version,
                $executedAt instanceof DateTimeImmutable ? $executedAt : null,
                $executionTime,
            );
        }

        uasort(
            $migrations,
            fn (ExecutedMigration $a, ExecutedMigration $b): int => $this->comparator->compare(
                $a->getVersion(),
                $b->getVersion(),
            ),
        );

        return new ExecutedMigrationsList($migrations);
    }

    public function reset(): void
    {
        $this->assertSchemaReady();
        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE 1 = 1', $this->configuration->getTableName()),
        );
    }

    public function complete(ExecutionResult $result): void
    {
        $this->assertSchemaReady();

        if ($result->getDirection() === Direction::DOWN) {
            $this->connection->delete($this->configuration->getTableName(), [
                $this->configuration->getVersionColumnName() => (string) $result->getVersion(),
            ]);

            return;
        }

        $this->connection->insert($this->configuration->getTableName(), [
            $this->configuration->getVersionColumnName() => (string) $result->getVersion(),
            $this->configuration->getExecutedAtColumnName() => $result->getExecutedAt(),
            $this->configuration->getExecutionTimeColumnName() => $result->getTime() === null
                ? null
                : (int) round($result->getTime() * 1000),
        ], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::INTEGER,
        ]);
    }

    /** @return iterable<Query> */
    public function getSql(ExecutionResult $result): iterable
    {
        yield new Query('-- Version ' . (string) $result->getVersion() . ' update table metadata');

        if ($result->getDirection() === Direction::DOWN) {
            yield new Query(sprintf(
                'DELETE FROM %s WHERE %s = %s',
                $this->configuration->getTableName(),
                $this->configuration->getVersionColumnName(),
                $this->connection->quote((string) $result->getVersion()),
            ));

            return;
        }

        yield new Query(sprintf(
            'INSERT INTO %s (%s, %s, %s) VALUES (%s, %s, 0)',
            $this->configuration->getTableName(),
            $this->configuration->getVersionColumnName(),
            $this->configuration->getExecutedAtColumnName(),
            $this->configuration->getExecutionTimeColumnName(),
            $this->connection->quote((string) $result->getVersion()),
            $this->connection->quote(($result->getExecutedAt() ?? new DateTimeImmutable())->format('Y-m-d H:i:s')),
        ));
    }

    public function ensureInitialized(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist([$this->configuration->getTableName()])) {
            $schemaManager->createTable($this->getExpectedTable());
            $this->schemaUpToDate = true;

            return;
        }

        $diff = $this->compareExpectedTable();
        if ($diff !== null) {
            $schemaManager->alterTable($diff);
        }

        $this->schemaUpToDate = true;
    }

    private function assertSchemaReady(): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist([$this->configuration->getTableName()])) {
            throw MetadataStorageError::notInitialized();
        }

        if (!$this->schemaUpToDate && $this->compareExpectedTable() !== null) {
            throw MetadataStorageError::notUpToDate();
        }
    }

    private function compareExpectedTable(): ?\Doctrine\DBAL\Schema\TableDiff
    {
        if ($this->schemaUpToDate) {
            return null;
        }

        $schemaManager = $this->connection->createSchemaManager();
        $comparator = class_exists(ComparatorConfig::class)
            ? $schemaManager->createComparator((new ComparatorConfig())->withReportModifiedIndexes(false))
            : $schemaManager->createComparator();

        $currentTable = $schemaManager->introspectTable($this->configuration->getTableName());
        $diff = $comparator->compareTables($currentTable, $this->getExpectedTable());

        if ($diff->isEmpty()) {
            return null;
        }

        if ($this->isMariaDbExecutedAtFalsePositive($diff)) {
            return null;
        }

        return $diff;
    }

    private function isMariaDbExecutedAtFalsePositive(\Doctrine\DBAL\Schema\TableDiff $diff): bool
    {
        $changedColumns = array_values($diff->getChangedColumns());
        if (count($changedColumns) !== 1) {
            return false;
        }

        $columnDiff = $changedColumns[0];
        if ($columnDiff === null) {
            return false;
        }

        if ($columnDiff->getOldColumn()->getName() !== $this->configuration->getExecutedAtColumnName()) {
            return false;
        }

        return !$columnDiff->hasTypeChanged()
            && !$columnDiff->hasNotNullChanged()
            && !$columnDiff->hasLengthChanged()
            && !$columnDiff->hasNameChanged();
    }

    private function getExpectedTable(): Table
    {
        $table = new Table($this->configuration->getTableName());
        $table->addColumn(
            $this->configuration->getVersionColumnName(),
            'string',
            ['notnull' => true, 'length' => $this->configuration->getVersionColumnLength()],
        );
        $table->addColumn($this->configuration->getExecutedAtColumnName(), 'datetime', ['notnull' => false]);
        $table->addColumn($this->configuration->getExecutionTimeColumnName(), 'integer', ['notnull' => false]);
        $table->setPrimaryKey([$this->configuration->getVersionColumnName()]);

        return $table;
    }
}
