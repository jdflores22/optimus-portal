<?php

namespace App\Tests\Unit\Command;

use App\Command\CleanupExpiredTokensCommand;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupExpiredTokensCommandTest extends TestCase
{
    private MockObject $pendingUserService;
    private MockObject $emailNotificationService;
    private MockObject $logger;
    private CleanupExpiredTokensCommand $command;

    protected function setUp(): void
    {
        $this->pendingUserService = $this->createMock(PendingUserService::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->command = new CleanupExpiredTokensCommand(
            $this->pendingUserService,
            $this->emailNotificationService,
            $this->logger
        );
    }

    public function testCommandIsConfiguredCorrectly(): void
    {
        $this->assertEquals('app:cleanup-expired-tokens', $this->command->getName());
        $this->assertEquals('Clean up expired role acceptance tokens and notify admins', $this->command->getDescription());
    }

    public function testServiceDependenciesAreInjected(): void
    {
        // Test that the command can be instantiated with the required services
        $this->assertInstanceOf(CleanupExpiredTokensCommand::class, $this->command);
    }
}