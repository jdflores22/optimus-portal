<?php

namespace App\Command;

use App\Entity\User;
use App\Service\InAppNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-sample-notifications',
    description: 'Create sample notifications for testing'
)]
class CreateSampleNotificationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InAppNotificationService $notificationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get all users
        $users = $this->entityManager->getRepository(User::class)->findAll();

        if (empty($users)) {
            $io->error('No users found in the database.');
            return Command::FAILURE;
        }

        $io->info('Creating sample notifications...');

        foreach ($users as $user) {
            // Create different types of notifications
            $this->notificationService->createSuccessNotification(
                $user,
                'Welcome to the new notification system!',
                'You can now receive real-time notifications about important updates and activities.',
                null,
                null
            );

            $this->notificationService->createInfoNotification(
                $user,
                'System Update',
                'The system has been updated with new features and improvements.',
                '/admin/user-hierarchy',
                'View Updates'
            );

            $this->notificationService->createWarningNotification(
                $user,
                'Profile Incomplete',
                'Please complete your profile information to ensure full access to all features.',
                '/profile/settings',
                'Complete Profile'
            );

            if ($user->getRole()->value === 'SHIPPING_LINES_ADMIN') {
                $this->notificationService->createInfoNotification(
                    $user,
                    'Team Management',
                    'You can now manage your team members through the user hierarchy section.',
                    '/admin/user-hierarchy',
                    'Manage Team'
                );
            }
        }

        $io->success(sprintf('Created sample notifications for %d users.', count($users)));

        return Command::SUCCESS;
    }
}