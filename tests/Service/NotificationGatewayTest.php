<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\NotificationGateway;
use App\Service\PushNotificationService;
use App\Service\InAppNotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class NotificationGatewayTest extends TestCase
{
    private NotificationGateway $gateway;
    private PushNotificationService $pushService;
    private InAppNotificationService $inAppService;
    private MailerInterface $mailer;
    private Environment $twig;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->pushService = $this->createMock(PushNotificationService::class);
        $this->inAppService = $this->createMock(InAppNotificationService::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gateway = new NotificationGateway(
            $this->pushService,
            $this->inAppService,
            $this->mailer,
            $this->twig,
            $this->logger
        );
    }

    public function testGetAvailableChannelsWithPushEnabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(true);

        $channels = $this->gateway->getAvailableChannels($user);

        $this->assertContains('push', $channels);
        $this->assertContains('in_app', $channels);
        $this->assertContains('email', $channels);
        $this->assertCount(3, $channels);
    }

    public function testGetAvailableChannelsWithoutPush(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(false);

        $channels = $this->gateway->getAvailableChannels($user);

        $this->assertNotContains('push', $channels);
        $this->assertContains('in_app', $channels);
        $this->assertContains('email', $channels);
        $this->assertCount(2, $channels);
    }

    public function testSendNotificationToAllChannels(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(true);

        // Expect push notification to be sent
        $this->pushService->expects($this->once())
            ->method('sendPushNotification')
            ->with(
                $user,
                'Test Subject',
                'Test Message',
                'manifest_payment_required',
                ['manifest_id' => 123]
            );

        // Expect in-app notification to be created
        $this->inAppService->expects($this->once())
            ->method('createNotification')
            ->with(
                $user,
                'Test Subject',
                'Test Message',
                'manifest_payment_required',
                ['manifest_id' => 123]
            );

        // Expect email to be sent
        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('<html>Test Email</html>');

        $this->mailer->expects($this->once())
            ->method('send');

        $this->gateway->sendNotification(
            [$user],
            'Test Subject',
            'Test Message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );
    }

    public function testSendNotificationHandlesPushFailureGracefully(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(true);

        // Push service throws exception
        $this->pushService->expects($this->once())
            ->method('sendPushNotification')
            ->willThrowException(new \Exception('Push service unavailable'));

        // In-app and email should still be sent
        $this->inAppService->expects($this->once())
            ->method('createNotification');

        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('<html>Test Email</html>');

        $this->mailer->expects($this->once())
            ->method('send');

        // Should log the error
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->gateway->sendNotification(
            [$user],
            'Test Subject',
            'Test Message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );
    }

    public function testSendNotificationHandlesInAppFailureGracefully(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(true);

        // Push should be sent
        $this->pushService->expects($this->once())
            ->method('sendPushNotification');

        // In-app service throws exception
        $this->inAppService->expects($this->once())
            ->method('createNotification')
            ->willThrowException(new \Exception('Database error'));

        // Email should still be sent
        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('<html>Test Email</html>');

        $this->mailer->expects($this->once())
            ->method('send');

        // Should log the error
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->gateway->sendNotification(
            [$user],
            'Test Subject',
            'Test Message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );
    }

    public function testSendNotificationHandlesEmailFailureGracefully(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->with($user)
            ->willReturn(true);

        // Push should be sent
        $this->pushService->expects($this->once())
            ->method('sendPushNotification');

        // In-app should be created
        $this->inAppService->expects($this->once())
            ->method('createNotification');

        // Email service throws exception
        $this->twig->expects($this->once())
            ->method('render')
            ->willThrowException(new \Exception('Template not found'));

        // Should log the error
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->gateway->sendNotification(
            [$user],
            'Test Subject',
            'Test Message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );
    }

    public function testSendNotificationToMultipleRecipients(): void
    {
        $user1 = $this->createMock(User::class);
        $user1->method('getId')->willReturn(1);
        $user1->method('getEmail')->willReturn('user1@example.com');

        $user2 = $this->createMock(User::class);
        $user2->method('getId')->willReturn(2);
        $user2->method('getEmail')->willReturn('user2@example.com');

        $this->pushService->method('hasActiveSubscriptions')
            ->willReturn(false);

        // Expect in-app notification for both users
        $this->inAppService->expects($this->exactly(2))
            ->method('createNotification');

        // Expect email for both users
        $this->twig->expects($this->exactly(2))
            ->method('render')
            ->willReturn('<html>Test Email</html>');

        $this->mailer->expects($this->exactly(2))
            ->method('send');

        $this->gateway->sendNotification(
            [$user1, $user2],
            'Test Subject',
            'Test Message',
            'manifest_payment_required',
            ['manifest_id' => 123]
        );
    }
}
