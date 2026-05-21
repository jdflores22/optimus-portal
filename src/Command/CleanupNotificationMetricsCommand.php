<?php

namespace App\Command;

use App\Repository\NotificationMetricsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-notification-metrics',
    description: 'Delete notification metrics older than 1 year'
)]
class CleanupNotificationMetricsCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 365; // 1 year

    public function __construct(
        private NotificationMetricsRepository $metricsRepository,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Number of days to retain metrics data',
                self::DEFAULT_RETENTION_DAYS
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Perform a dry run without actually deleting data'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $retentionDays = (int) $input->getOption('retention-days');
        $dryRun = $input->getOption('dry-run');
        
        $io->title('Cleaning Up Notification Metrics');
        
        if ($dryRun) {
            $io->note('Running in DRY RUN mode - no data will be deleted');
        }
        
        // Calculate cutoff date
        $cutoffDate = new \DateTime();
        $cutoffDate->modify("-{$retentionDays} days");
        
        $io->info(sprintf(
            'Deleting metrics older than %s (%d days)',
            $cutoffDate->format('Y-m-d H:i:s'),
            $retentionDays
        ));
        
        // Count metrics to be deleted
        $qb = $this->metricsRepository->createQueryBuilder('nm');
        $countToDelete = $qb->select('COUNT(nm.id)')
            ->where('nm.sentAt < :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($countToDelete == 0) {
            $io->success('No metrics found older than the retention period');
            return Command::SUCCESS;
        }
        
        $io->section('Metrics to Delete');
        $io->text(sprintf('Found %d metrics records to delete', $countToDelete));
        
        if ($dryRun) {
            $io->warning('DRY RUN: No data was deleted');
            return Command::SUCCESS;
        }
        
        // Confirm deletion
        if (!$io->confirm(sprintf('Are you sure you want to delete %d metrics records?', $countToDelete), false)) {
            $io->note('Cleanup cancelled');
            return Command::SUCCESS;
        }
        
        // Perform deletion
        $io->progressStart($countToDelete);
        
        try {
            $deletedCount = $this->metricsRepository->deleteOlderThan($cutoffDate);
            
            $io->progressFinish();
            
            $io->success(sprintf('Successfully deleted %d metrics records', $deletedCount));
            
            // Log the cleanup
            $this->logger->info('Notification metrics cleanup completed', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays,
                'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s')
            ]);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to delete metrics: ' . $e->getMessage());
            
            $this->logger->error('Notification metrics cleanup failed', [
                'error' => $e->getMessage(),
                'retention_days' => $retentionDays
            ]);
            
            return Command::FAILURE;
        }
    }
}
