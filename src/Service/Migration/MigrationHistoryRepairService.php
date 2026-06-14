<?php

declare(strict_types=1);

namespace App\Service\Migration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Symfony\Component\Process\Process;

final class MigrationHistoryRepairService
{
    private const METADATA_TABLE = 'doctrine_migration_versions';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsDirectory,
        private readonly string $projectDir,
        private readonly TableMetadataStorageConfiguration $metadataConfiguration = new TableMetadataStorageConfiguration(),
    ) {
    }

    public function getDatabaseName(): string
    {
        return (string) $this->connection->getDatabase();
    }

    public function getServerVersion(): string
    {
        return (string) $this->connection->fetchOne('SELECT VERSION()');
    }

    /** @return list<array{from: string, to: string}> */
    public function normalizeVersionStrings(bool $dryRun = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT version FROM %s ORDER BY version', self::METADATA_TABLE)
        );

        $updates = [];
        foreach ($rows as $row) {
            $current = (string) $row['version'];
            $normalized = $this->normalizeVersion($current);
            if ($normalized === $current) {
                continue;
            }

            $updates[] = ['from' => $current, 'to' => $normalized];
            if ($dryRun) {
                continue;
            }

            $targetExists = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE version = ?', self::METADATA_TABLE),
                [$normalized]
            ) > 0;

            if ($targetExists) {
                $this->connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE version = ?', self::METADATA_TABLE),
                    [$current]
                );
                continue;
            }

            $this->connection->executeStatement(
                sprintf('UPDATE %s SET version = ? WHERE version = ?', self::METADATA_TABLE),
                [$normalized, $current]
            );
        }

        return $updates;
    }

    public function rebuildMetadataTable(bool $dryRun = false, ?string $appEnv = null): int
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT version, executed_at, execution_time FROM %s', self::METADATA_TABLE)
        );

        if ($dryRun) {
            return count($rows);
        }

        $this->connection->executeStatement(sprintf('DROP TABLE %s', self::METADATA_TABLE));
        $this->runConsole(['doctrine:migrations:sync-metadata-storage', '--no-interaction'], $appEnv);

        foreach ($rows as $row) {
            $executionTime = $row['execution_time'];
            if ($executionTime !== null && (int) $executionTime < 1000) {
                $executionTime = (int) $executionTime * 1000;
            }

            $this->connection->insert(self::METADATA_TABLE, [
                'version' => $this->normalizeVersion((string) $row['version']),
                'executed_at' => $row['executed_at'],
                'execution_time' => $executionTime,
            ]);
        }

        return count($rows);
    }

    public function syncMetadataStorage(?string $appEnv = null): void
    {
        $this->runConsole(['doctrine:migrations:sync-metadata-storage', '--no-interaction'], $appEnv);
    }

    /** @return list<string> */
    public function discoverMigrationClasses(bool $skipRollback = true): array
    {
        $classes = [];
        foreach (glob($this->migrationsDirectory . '/Version*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if ($skipRollback && str_contains($base, '_Rollback')) {
                continue;
            }
            $classes[] = 'DoctrineMigrations\\' . $base;
        }

        sort($classes);

        return $classes;
    }

    /** @return list<string> */
    public function getMissingMigrationClasses(bool $skipRollback = true): array
    {
        $available = $this->discoverMigrationClasses($skipRollback);
        $recorded = $this->connection->fetchFirstColumn(
            sprintf('SELECT version FROM %s', self::METADATA_TABLE)
        );
        $recordedMap = array_fill_keys(
            array_map(fn ($v) => $this->normalizeVersion((string) $v), $recorded),
            true
        );

        return array_values(array_filter(
            $available,
            static fn (string $class) => !isset($recordedMap[$class])
        ));
    }

    /** @param list<string> $classes */
    public function markMigrationsAsExecuted(array $classes, bool $dryRun = false): int
    {
        $marked = 0;
        $now = new DateTimeImmutable();

        foreach ($classes as $class) {
            if ($dryRun) {
                ++$marked;
                continue;
            }

            $exists = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE version = ?', self::METADATA_TABLE),
                [$class]
            ) > 0;

            if ($exists) {
                continue;
            }

            $this->connection->insert(self::METADATA_TABLE, [
                'version' => $class,
                'executed_at' => $now->format('Y-m-d H:i:s'),
                'execution_time' => 0,
            ]);
            ++$marked;
        }

        return $marked;
    }

    public function removeUnavailableMigrationRecords(): int
    {
        $available = array_fill_keys($this->discoverMigrationClasses(false), true);
        $recorded = $this->connection->fetchFirstColumn(
            sprintf('SELECT version FROM %s', self::METADATA_TABLE)
        );

        $removed = 0;
        foreach ($recorded as $version) {
            $normalized = $this->normalizeVersion((string) $version);
            if (isset($available[$normalized])) {
                continue;
            }

            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE version = ?', self::METADATA_TABLE),
                [$version]
            );
            ++$removed;
        }

        return $removed;
    }

    public function restorePaymentVersionsFromBackup(bool $dryRun = false): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['payments_version_backup'])) {
            return ['restored' => 0, 'message' => 'payments_version_backup table does not exist.'];
        }

        $backupCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payments_version_backup');
        if ($backupCount === 0) {
            return ['restored' => 0, 'message' => 'payments_version_backup is empty.'];
        }

        if ($dryRun) {
            return ['restored' => $backupCount, 'message' => sprintf('Would restore %d payment row(s).', $backupCount)];
        }

        $this->connection->executeStatement('
            UPDATE payments p
            INNER JOIN payments_version_backup b ON p.id = b.id
            SET p.version = b.version, p.previous_payment_id = b.previous_payment_id
        ');

        return [
            'restored' => $backupCount,
            'message' => sprintf('Restored version data for %d payment row(s) from payments_version_backup.', $backupCount),
        ];
    }

    public function normalizeVersion(string $version): string
    {
        $version = str_replace('\\\\', '\\', $version);

        if (str_starts_with($version, 'DoctrineMigrationsVersion')) {
            return 'DoctrineMigrations\\' . substr($version, strlen('DoctrineMigrations'));
        }

        return $version;
    }

    /** @param list<string> $arguments */
    private function runConsole(array $arguments, ?string $appEnv = null): void
    {
        $command = array_merge([PHP_BINARY, $this->projectDir . '/bin/console'], $arguments);
        if ($appEnv !== null) {
            $command[] = '--env=' . $appEnv;
        }

        $process = new Process($command, $this->projectDir, null, null, 120);
        $process->mustRun();
    }
}
