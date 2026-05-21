<?php

namespace App\Command;

use App\Service\PendingUserService;
use App\Service\InAppNotificationService;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-email-delivery-failures',
    description: 'Process email delivery failures and notify admins about failed invitations'
)]
class ProcessEmailDeliveryFailuresCommand extends Command
{
    public function __construct(
        private PendingUserService $pendingUserService,
        private InAppNotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'notify-admins',
            null,
            InputOption::VALUE_NONE,
            'Send notifications to admins about delivery failures'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Processing Email Delivery Failures');

        try {
            // Get all delivery failed invitations
            $failedInvitations = $this->pendingUserService->getDeliveryFailedInvitations();
            $failedCount = count($failedInvitations);

            if ($failedCount === 0) {
                $io->info('No delivery failed invitations found');
                return Command::SUCCESS;
            }

            $io->section("Found {$failedCount} delivery failed invitation(s)");

            // Display failed invitations
            $tableData = [];
            $adminNotifications = [];

            foreach ($failedInvitations as $pendingUser) {
                $tableData[] = [
                    $pendingUser->getId(),
                    $pendingUser->getEmail(),
                    $pendingUser->getFullName(),
                    $pendingUser->getRole()->value,
                    $pendingUser->getCreatedByAdmin()->getEmail(),
                    $pendingUser->getCreatedAt()->format('Y-m-d H:i:s')
                ];

                // Collect admin notifications
                $adminId = $pendingUser->getCreatedByAdmin()->getId();
                if (!isset($adminNotifications[$adminId])) {
                    $adminNotifications[$adminId] = [
                        'admin' => $pendingUser->getCreatedByAdmin(),
                        'count' => 0,
                        'invitations' => []
                    ];
                }
                $adminNotifications[$adminId]['count']++;
                $adminNotifications[$adminId]['invitations'][] = $pendingUser;
            }

            $io->table(
                ['ID', 'Email', 'Name', 'Role', 'Created By', 'Created At'],
                $tableData
            );

            // Send notifications to admins if requested
            if ($input->getOption('notify-admins')) {
                $this->sendAdminNotifications($adminNotifications, $io);
            }

            // Display statistics
            $this->displayFailureStatistics($io);

            $io->success('Email delivery failure processing completed');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to process email delivery failures: ' . $e->getMessage());
            
            $this->logger->error('Email delivery failure processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    private function sendAdminNotifications(array $adminNotifications, SymfonyStyle $io): void
    {
        $io->section('Sending Admin Notifications');

        foreach ($adminNotifications as $data) {
            $admin = $data['admin'];
            $count = $data['count'];
            $invitations = $data['invitations'];

            try {
                $message = $count === 1 
                    ? "You have 1 invitation with email delivery failure that requires attention."
                    : "You have {$count} invitations with email delivery failures that require attention.";

                $this->notificationService->createNotification(
                    $admin,
                    'Email Delivery Failures Detected',
                    $message,
                    'warning'
                );

                $io->writeln("✓ Notified {$admin->getEmail()} about {$count} failed invitation(s)");

                $this->logger->info('Admin notified about delivery failures', [
                    'admin_id' => $admin->getId(),
                    'admin_email' => $admin->getEmail(),
                    'failed_count' => $count
                ]);

            } catch (\Exception $e) {
                $io->writeln("✗ Failed to notify {$admin->getEmail()}: {$e->getMessage()}");
                
                $this->logger->error('Failed to notify admin about delivery failures', [
                    'admin_id' => $admin->getId(),
                    'admin_email' => $admin->getEmail(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function displayFailureStatistics(SymfonyStyle $io): void
    {
        try {
            $stats = $this->pendingUserService->getPendingUserStatistics();
            
            $io->section('Email Delivery Statistics');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total Pending Users', $stats['total']],
                    ['Delivery Failed', $stats['delivery_failed']],
                    ['Pending (Active)', $stats['pending']],
                    ['Expired', $stats['expired']],
                    ['Failure Rate', $stats['total'] > 0 ? round(($stats['delivery_failed'] / $stats['total']) * 100, 2) . '%' : '0%']
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not retrieve delivery statistics: ' . $e->getMessage());
        }
    }
}