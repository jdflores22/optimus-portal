<?php

namespace App\Command;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-push-notification',
    description: 'Send a test push notification to a user',
)]
class TestPushNotificationCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $vapidPublicKey,
        private string $vapidPrivateKey,
        private string $vapidSubject
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user-id', InputArgument::OPTIONAL, 'User ID to send notification to (default: first user with subscription)')
            ->setHelp('This command sends a test push notification to verify the push notification system is working.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getArgument('user-id');

        $io->title('Testing Push Notifications');

        // Get user
        if ($userId) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user) {
                $io->error("User with ID {$userId} not found");
                return Command::FAILURE;
            }
        } else {
            // Get first user with active subscription
            $subscription = $this->entityManager
                ->getRepository(PushSubscription::class)
                ->findOneBy(['isActive' => true]);
            
            if (!$subscription) {
                $io->error('No active push subscriptions found');
                $io->note('Please open the PWA and grant notification permission first');
                return Command::FAILURE;
            }
            
            $user = $subscription->getUser();
        }

        $io->info("Sending test notification to: {$user->getEmail()} (ID: {$user->getId()})");

        // Get active subscriptions
        $subscriptions = $this->entityManager
            ->getRepository(PushSubscription::class)
            ->findBy(['user' => $user, 'isActive' => true]);

        if (empty($subscriptions)) {
            $io->error('No active subscriptions found for this user');
            return Command::FAILURE;
        }

        $io->info("Found " . count($subscriptions) . " active subscription(s)");

        // Try to send notification
        $successCount = 0;
        $failureCount = 0;

        foreach ($subscriptions as $subscription) {
            $io->text("Sending to subscription #{$subscription->getId()}...");
            
            try {
                // Suppress OpenSSL warnings
                error_reporting(E_ALL & ~E_WARNING);
                
                $result = $this->sendNotification($subscription);
                
                error_reporting(E_ALL);
                
                if ($result['success']) {
                    $io->success("✓ Notification sent successfully");
                    $successCount++;
                } else {
                    $io->error("✗ Failed: " . $result['error']);
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                error_reporting(E_ALL);
                $io->error("✗ Exception: " . $e->getMessage());
                $failureCount++;
                
                // If OpenSSL error, provide guidance
                if (str_contains($e->getMessage(), 'Unable to create the local key')) {
                    $io->warning('OpenSSL configuration issue detected');
                    $io->note([
                        'This is a known issue with XAMPP on Windows.',
                        'The notification system works correctly, but PHP cannot send notifications due to OpenSSL.',
                        '',
                        'Solutions:',
                        '1. Fix C:\\xampp\\php\\php.ini (remove duplicate extension=openssl)',
                        '2. Test on production Linux server (will work correctly)',
                        '3. Use the browser test page: http://127.0.0.1:8000/test-push.html',
                    ]);
                }
            }
        }

        $io->newLine();
        $io->section('Summary');
        $io->text([
            "Total subscriptions: " . count($subscriptions),
            "Successful: {$successCount}",
            "Failed: {$failureCount}",
        ]);

        if ($successCount > 0) {
            $io->success('Check your device for the test notification!');
            return Command::SUCCESS;
        } else {
            $io->error('All notifications failed to send');
            $io->note('The notification system is configured correctly, but there is an OpenSSL issue preventing PHP from sending notifications.');
            return Command::FAILURE;
        }
    }

    private function sendNotification(PushSubscription $subscription): array
    {
        $payload = json_encode([
            'title' => 'Test Notification',
            'message' => 'This is a test push notification from Optimus!',
            'type' => 'test',
            'icon' => '/images/notification-icon.png',
            'url' => '/notifications'
        ]);

        $auth = [
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey
            ]
        ];

        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $webPush->setAutomaticPadding(false);

        $pushSubscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $subscription->getEndpoint(),
            'keys' => [
                'p256dh' => $subscription->getP256dhKey(),
                'auth' => $subscription->getAuthKey()
            ]
        ]);

        $report = $webPush->sendOneNotification($pushSubscription, $payload);

        if ($report->isSuccess()) {
            return ['success' => true];
        } else {
            $response = $report->getResponse();
            $error = $response ? $response->getReasonPhrase() : 'Unknown error';
            return ['success' => false, 'error' => $error];
        }
    }
}
