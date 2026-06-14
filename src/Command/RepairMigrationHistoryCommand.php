<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Migration\MigrationHistoryRepairService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrations:repair',
    description: 'Repair doctrine_migration_versions using .env-driven defaults (MariaDB/XAMPP safe)',
)]
final class RepairMigrationHistoryCommand extends Command
{
    public function __construct(
        private readonly MigrationHistoryRepairService $repairService,
        private readonly bool $defaultRebuildMetadata,
        private readonly bool $defaultMarkApplied,
        private readonly bool $defaultSkipRollback,
        private readonly string $configuredServerVersion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing')
            ->addOption('normalize-only', null, InputOption::VALUE_NONE, 'Only fix malformed version strings')
            ->addOption('rebuild-metadata', null, InputOption::VALUE_NONE, 'Drop and recreate doctrine_migration_versions')
            ->addOption('no-rebuild-metadata', null, InputOption::VALUE_NONE, 'Skip metadata table rebuild')
            ->addOption('mark-applied', null, InputOption::VALUE_NONE, 'Mark on-disk migrations as executed (no SQL)')
            ->addOption('no-mark-applied', null, InputOption::VALUE_NONE, 'Do not mark missing migrations')
            ->addOption('include-rollback', null, InputOption::VALUE_NONE, 'Include *_Rollback migration classes')
            ->addOption('restore-payments', null, InputOption::VALUE_NONE, 'Restore payment versions from payments_version_backup')
            ->addOption('prune-unavailable', null, InputOption::VALUE_NONE, 'Remove history rows with no matching migration file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Migration history repair');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Database', $this->repairService->getDatabaseName()],
                ['Server', $this->repairService->getServerVersion()],
                ['DATABASE_SERVER_VERSION (.env)', $this->configuredServerVersion],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ]
        );

        if (!str_contains(strtolower($this->repairService->getServerVersion()), 'mariadb')
            && str_starts_with(strtolower($this->configuredServerVersion), 'mariadb')) {
            $io->warning('DATABASE_SERVER_VERSION mentions MariaDB but server reports a different product string.');
        } elseif (str_contains(strtolower($this->repairService->getServerVersion()), 'mariadb')
            && str_contains($this->configuredServerVersion, '8.0')) {
            $io->warning('DATABASE_SERVER_VERSION is MySQL 8.0 but server is MariaDB. Update .env to e.g. mariadb-10.4.32 to reduce schema drift.');
        }

        $updates = $this->repairService->normalizeVersionStrings($dryRun);
        if ($updates === []) {
            $io->comment('No malformed migration version strings found.');
        } else {
            $io->section('Normalizing version strings');
            foreach ($updates as $update) {
                $io->text(sprintf('%s → %s', $update['from'], $update['to']));
            }
        }

        $rebuild = $this->resolveFlag(
            $input,
            'rebuild-metadata',
            'no-rebuild-metadata',
            $this->defaultRebuildMetadata
        );

        if ($rebuild) {
            $count = $this->repairService->rebuildMetadataTable($dryRun);
            $io->success($dryRun
                ? sprintf('Would rebuild metadata table (%d row(s)).', $count)
                : sprintf('Rebuilt metadata table (%d row(s)).', $count));
        } elseif (!$dryRun) {
            $this->repairService->syncMetadataStorage();
        }

        if ($input->getOption('prune-unavailable')) {
            $removed = $dryRun
                ? 0
                : $this->repairService->removeUnavailableMigrationRecords();
            $io->info($dryRun ? 'Would prune unavailable migration records.' : sprintf('Removed %d unavailable record(s).', $removed));
        }

        if ($input->getOption('restore-payments')) {
            $result = $this->repairService->restorePaymentVersionsFromBackup($dryRun);
            $io->success($result['message']);
        }

        if ($input->getOption('normalize-only')) {
            $io->note('Verify: php bin/console doctrine:migrations:status');
            return Command::SUCCESS;
        }

        $markApplied = $this->resolveFlag(
            $input,
            'mark-applied',
            'no-mark-applied',
            $this->defaultMarkApplied
        );

        $skipRollback = !$input->getOption('include-rollback') && $this->defaultSkipRollback;
        $missing = $this->repairService->getMissingMigrationClasses($skipRollback);

        $io->section('Migration coverage');
        $io->text(sprintf('%d migration(s) missing from history.', count($missing)));

        if ($markApplied && $missing !== []) {
            $marked = $this->repairService->markMigrationsAsExecuted($missing, $dryRun);
            $io->success($dryRun
                ? sprintf('Would mark %d migration(s) as executed.', $marked)
                : sprintf('Marked %d migration(s) as executed.', $marked));
        } elseif ($missing !== []) {
            $io->note('Set MIGRATION_REPAIR_MARK_APPLIED=true in .env or pass --mark-applied to sync history.');
        } else {
            $io->success('Migration history is complete.');
        }

        if (!$dryRun) {
            $this->repairService->syncMetadataStorage();
        }

        $io->note('Verify: php bin/console doctrine:migrations:status');
        $io->note('Migrate: php bin/console doctrine:migrations:migrate --no-interaction');

        return Command::SUCCESS;
    }

    private function resolveFlag(InputInterface $input, string $yesFlag, string $noFlag, bool $default): bool
    {
        if ($input->getOption($yesFlag)) {
            return true;
        }
        if ($input->getOption($noFlag)) {
            return false;
        }

        return $default;
    }
}
