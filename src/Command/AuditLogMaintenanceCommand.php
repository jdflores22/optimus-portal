<?php

namespace App\Command;

use App\Service\AuditLogRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit-log:maintenance',
    description: 'Perform audit log maintenance (archival and cleanup)',
)]
class AuditLogMaintenanceCommand extends Command
{
    public function __construct(
        private AuditLogRetentionService $retentionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('archive', 'a', InputOption::VALUE_NONE, 'Archive old audit logs')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete expired audit logs')
            ->addOption('stats', 's', InputOption::VALUE_NONE, 'Show audit log statistics')
            ->addOption('policy', 'p', InputOption::VALUE_NONE, 'Show retention policy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Show retention policy
        if ($input->getOption('policy')) {
            $this->showRetentionPolicy($io);
            return Command::SUCCESS;
        }

        // Show statistics
        if ($input->getOption('stats')) {
            $this->showStatistics($io);
            return Command::SUCCESS;
        }

        $dryRun = $input->getOption('dry-run');
        
        if ($dryRun) {
            $io->warning('DRY RUN MODE - No changes will be made');
        }

        // Archive old logs
        if ($input->getOption('archive')) {
            $io->section('Archiving Old Audit Logs');
            
            if ($dryRun) {
                $stats = $this->retentionService->getStatistics();
                $io->info("Would archive {$stats['eligible_for_archival']} logs");
            } else {
                $result = $this->retentionService->archiveOldLogs();
                $io->success($result['message']);
                $io->table(
                    ['Metric', 'Value'],
                    [
                        ['Logs Archived', $result['archived']],
                        ['Months Processed', $result['months'] ?? 0],
                    ]
                );
            }
        }

        // Delete expired logs
        if ($input->getOption('delete')) {
            $io->section('Deleting Expired Audit Logs');
            
            if ($dryRun) {
                $stats = $this->retentionService->getStatistics();
                $io->info("Would delete {$stats['eligible_for_deletion']} logs");
            } else {
                if (!$io->confirm('Are you sure you want to permanently delete expired audit logs?', false)) {
                    $io->warning('Deletion cancelled');
                    return Command::SUCCESS;
                }
                
                $result = $this->retentionService->deleteExpiredLogs();
                $io->success($result['message']);
                $io->table(
                    ['Metric', 'Value'],
                    [
                        ['Logs Deleted', $result['deleted']],
                        ['Cutoff Date', $result['cutoff_date']],
                    ]
                );
            }
        }

        // If no specific action, show help
        if (!$input->getOption('archive') && !$input->getOption('delete')) {
            $io->note('Use --archive to archive old logs, --delete to remove expired logs, or --stats to view statistics');
            $this->showStatistics($io);
        }

        return Command::SUCCESS;
    }

    private function showRetentionPolicy(SymfonyStyle $io): void
    {
        $io->title('Audit Log Retention Policy');
        
        $policy = $this->retentionService->getRetentionPolicy();
        
        $io->table(
            ['Setting', 'Value'],
            [
                ['Retention Period', $policy['retention_years'] . ' years (' . $policy['retention_days'] . ' days)'],
                ['Archive Enabled', $policy['archive_enabled'] ? 'Yes' : 'No'],
                ['Archive After', floor($policy['archive_after_days'] / 365) . ' years (' . $policy['archive_after_days'] . ' days)'],
                ['Archive Path', $policy['archive_path']],
            ]
        );
        
        $io->note([
            'Logs older than ' . floor($policy['archive_after_days'] / 365) . ' years will be archived to file storage.',
            'Logs older than ' . $policy['retention_years'] . ' years will be permanently deleted.',
            'This ensures compliance with the 7-year retention requirement.',
        ]);
    }

    private function showStatistics(SymfonyStyle $io): void
    {
        $io->title('Audit Log Statistics');
        
        $stats = $this->retentionService->getStatistics();
        
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Audit Logs', number_format($stats['total_logs'])],
                ['Eligible for Archival', number_format($stats['eligible_for_archival'])],
                ['Eligible for Deletion', number_format($stats['eligible_for_deletion'])],
                ['Oldest Log Date', $stats['oldest_log_date'] ?? 'N/A'],
                ['Archive Cutoff Date', $stats['archive_cutoff_date']],
                ['Retention Cutoff Date', $stats['retention_cutoff_date']],
            ]
        );
        
        if ($stats['eligible_for_archival'] > 0) {
            $io->note('Run with --archive to archive ' . number_format($stats['eligible_for_archival']) . ' old logs');
        }
        
        if ($stats['eligible_for_deletion'] > 0) {
            $io->warning('There are ' . number_format($stats['eligible_for_deletion']) . ' logs eligible for deletion');
            $io->note('Run with --delete to permanently remove expired logs');
        }
    }
}
