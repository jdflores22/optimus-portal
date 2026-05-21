<?php

namespace App\Command;

use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cleanup notification history older than 90 days
 * Implements Requirement 8.6: PWA SHALL retain notification history for 90 days
 */
#[AsCommand(
    name: 'app:cleanup-notification-history',
    description: 'Delete notifications older than 90 days to maintain retention policy'
)]
class CleanupNotificationHistoryCommand extends Command
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Notification History Cleanup');
        $io->text('Deleting notifications older than 90 days...');

        try {
            $deletedCount = $this->notificationRepository->deleteNotificationsOlderThan90Days();

            $this->logger->info('Notification history cleanup completed', [
                'deleted_count' => $deletedCount,
                'retention_days' => 90
            ]);

            $io->success(sprintf('Successfully deleted %d notifications older than 90 days.', $deletedCount));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->error('Notification history cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $io->error('Failed to cleanup notification history: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
