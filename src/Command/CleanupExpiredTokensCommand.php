<?php

namespace App\Command;

use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-expired-tokens',
    description: 'Clean up expired role acceptance tokens and notify admins'
)]
class CleanupExpiredTokensCommand extends Command
{
    public function __construct(
        private PendingUserService $pendingUserService,
        private EmailNotificationService $emailNotificationService,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Cleaning Up Expired Role Acceptance Tokens');

        try {
            // Get count of expired tokens before cleanup
            $expiredCount = $this->pendingUserService->expirePendingUsers();

            if ($expiredCount > 0) {
                $io->success("Cleaned up {$expiredCount} expired token(s)");
                
                $this->logger->info('Expired tokens cleaned up', [
                    'expired_count' => $expiredCount
                ]);
            } else {
                $io->info('No expired tokens found');
            }

            // Clean up accepted pending users
            $acceptedCount = $this->pendingUserService->cleanupAcceptedPendingUsers();

            if ($acceptedCount > 0) {
                $io->success("Cleaned up {$acceptedCount} accepted pending user(s)");
                
                $this->logger->info('Accepted pending users cleaned up', [
                    'accepted_count' => $acceptedCount
                ]);
            } else {
                $io->info('No accepted pending users to clean up');
            }

            // Clean up old declined pending users (older than 30 days)
            $declinedCount = $this->pendingUserService->cleanupDeclinedPendingUsers();

            if ($declinedCount > 0) {
                $io->success("Cleaned up {$declinedCount} old declined pending user(s)");
                
                $this->logger->info('Declined pending users cleaned up', [
                    'declined_count' => $declinedCount
                ]);
            } else {
                $io->info('No old declined pending users to clean up');
            }

            // Display pending user statistics
            $this->displayPendingUserStatistics($io);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to clean up expired tokens: ' . $e->getMessage());
            
            $this->logger->error('Token cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    private function displayPendingUserStatistics(SymfonyStyle $io): void
    {
        try {
            $stats = $this->pendingUserService->getPendingUserStatistics();
            
            $io->section('Pending User Statistics');
            $io->table(
                ['Status', 'Count'],
                [
                    ['Pending', $stats['pending'] ?? 0],
                    ['Expired', $stats['expired'] ?? 0],
                    ['Delivery Failed', $stats['delivery_failed'] ?? 0],
                    ['Accepted', $stats['accepted'] ?? 0],
                    ['Declined', $stats['declined'] ?? 0],
                    ['Total', $stats['total'] ?? 0]
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not retrieve pending user statistics: ' . $e->getMessage());
        }
    }
}