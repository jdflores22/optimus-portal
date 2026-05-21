<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\TerminalTeamUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service for integrating dwell time management with terminal team operations
 * Handles terminal team notifications and dashboard updates for dwell time alerts
 */
class TerminalTeamDwellTimeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InAppNotificationService $inAppService,
        private EmailNotificationService $emailService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Notify terminal team about dwell time warning (60-day threshold)
     */
    public function notifyTerminalTeamDwellTimeWarning(Container $container, int $daysRemaining): void
    {
        $terminalTeamUsers = $this->getActiveTerminalTeamUsers();
        
        if (empty($terminalTeamUsers)) {
            $this->logger->warning('No active terminal team users found for dwell time warning notification', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $this->logger->info('Notifying terminal team about dwell time warning', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'days_remaining' => $daysRemaining,
            'terminal_team_count' => count($terminalTeamUsers)
        ]);

        // Create in-app notifications for all terminal team members
        foreach ($terminalTeamUsers as $terminalUser) {
            $this->inAppService->createWarningNotification(
                $terminalUser,
                'Container Dwell Time Alert',
                sprintf(
                    'Container %s has reached 60 days dwell time. %d days remaining before automatic return. Please review and take appropriate action.',
                    $container->getContainerNumber(),
                    $daysRemaining
                ),
                $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
                'View Container'
            );
        }

        // Send email notification to terminal team
        $this->sendTerminalTeamEmail(
            $terminalTeamUsers,
            'Dwell Time Warning',
            $container,
            [
                'days_remaining' => $daysRemaining,
                'current_dwell_time' => $container->getCurrentDwellTime()
            ]
        );
    }

    /**
     * Notify terminal team about automatic container return (90-day threshold)
     */
    public function notifyTerminalTeamAutomaticReturn(Container $container): void
    {
        $terminalTeamUsers = $this->getActiveTerminalTeamUsers();
        
        if (empty($terminalTeamUsers)) {
            $this->logger->warning('No active terminal team users found for automatic return notification', [
                'container_id' => $container->getId(),
                'container_number' => $container->getContainerNumber()
            ]);
            return;
        }

        $this->logger->info('Notifying terminal team about automatic container return', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'dwell_time' => $container->getCurrentDwellTime(),
            'terminal_team_count' => count($terminalTeamUsers)
        ]);

        // Create in-app notifications for all terminal team members
        foreach ($terminalTeamUsers as $terminalUser) {
            $this->inAppService->createErrorNotification(
                $terminalUser,
                'Container Automatic Return',
                sprintf(
                    'Container %s has been automatically returned to terminal after %d days dwell time. Please update terminal records and coordinate container handling.',
                    $container->getContainerNumber(),
                    $container->getCurrentDwellTime()
                ),
                $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
                'View Container'
            );
        }

        // Send email notification to terminal team
        $this->sendTerminalTeamEmail(
            $terminalTeamUsers,
            'Automatic Container Return',
            $container,
            [
                'dwell_time' => $container->getCurrentDwellTime(),
                'return_date' => new \DateTime()
            ]
        );
    }

    /**
     * Notify terminal team about alert status change
     */
    public function notifyTerminalTeamAlertStatusChange(Container $container, bool $isAlerted, string $reason = ''): void
    {
        $terminalTeamUsers = $this->getActiveTerminalTeamUsers();
        
        if (empty($terminalTeamUsers)) {
            return;
        }

        $title = $isAlerted ? 'Container Alert Status Activated' : 'Container Alert Status Cleared';
        $message = $isAlerted 
            ? sprintf(
                'Container %s has been marked as alerted. Dwell time counting is paused. Reason: %s',
                $container->getContainerNumber(),
                $reason ?: 'Not specified'
            )
            : sprintf(
                'Container %s alert status has been cleared. Dwell time counting has resumed.',
                $container->getContainerNumber()
            );

        $this->logger->info('Notifying terminal team about alert status change', [
            'container_id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'is_alerted' => $isAlerted,
            'reason' => $reason
        ]);

        // Create in-app notifications for terminal team
        foreach ($terminalTeamUsers as $terminalUser) {
            $this->inAppService->createInfoNotification(
                $terminalUser,
                $title,
                $message,
                $this->urlGenerator->generate('container_detail', ['id' => $container->getId()]),
                'View Container'
            );
        }
    }

    /**
     * Get dashboard metrics for terminal team including dwell time information
     */
    public function getTerminalTeamDashboardMetrics(): array
    {
        $now = new \DateTime();
        
        // Get containers approaching 60-day threshold (50-59 days)
        $approachingWarning = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.currentDwellTime >= 50')
            ->andWhere('c.currentDwellTime < 60')
            ->andWhere('c.dwellTimePausedAt IS NULL')
            ->getQuery()
            ->getResult();

        // Get containers that have received 60-day warning (60-89 days)
        $warningIssued = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.currentDwellTime >= 60')
            ->andWhere('c.currentDwellTime < 90')
            ->andWhere('c.dwellTimePausedAt IS NULL')
            ->getQuery()
            ->getResult();

        // Get containers that have been automatically returned (90+ days)
        $automaticReturns = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.currentDwellTime >= 90')
            ->getQuery()
            ->getResult();

        // Get containers with alert status (paused dwell time)
        $alertedContainers = $this->entityManager->getRepository(Container::class)
            ->createQueryBuilder('c')
            ->where('c.status = :alertStatus')
            ->setParameter('alertStatus', \App\Entity\Enum\ContainerStatus::ALERT)
            ->getQuery()
            ->getResult();

        // Get recent dwell time events for terminal team visibility
        $recentEvents = $this->entityManager->getRepository(\App\Entity\DwellTimeEvent::class)
            ->createQueryBuilder('dte')
            ->where('dte.eventDate >= :since')
            ->setParameter('since', (clone $now)->modify('-7 days'))
            ->orderBy('dte.eventDate', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'dwell_time_summary' => [
                'approaching_warning_count' => count($approachingWarning),
                'warning_issued_count' => count($warningIssued),
                'automatic_returns_count' => count($automaticReturns),
                'alerted_containers_count' => count($alertedContainers)
            ],
            'containers_approaching_warning' => array_map(
                fn($c) => $this->formatContainerForDashboard($c),
                $approachingWarning
            ),
            'containers_with_warning' => array_map(
                fn($c) => $this->formatContainerForDashboard($c),
                $warningIssued
            ),
            'automatic_returns' => array_map(
                fn($c) => $this->formatContainerForDashboard($c),
                $automaticReturns
            ),
            'alerted_containers' => array_map(
                fn($c) => $this->formatContainerForDashboard($c),
                $alertedContainers
            ),
            'recent_events' => array_map(
                fn($e) => $this->formatEventForDashboard($e),
                $recentEvents
            )
        ];
    }

    /**
     * Get alert status visibility for terminal team
     */
    public function getContainerAlertStatusInfo(Container $container): array
    {
        $isAlerted = $container->getStatus() === \App\Entity\Enum\ContainerStatus::ALERT;
        $isPaused = $container->getDwellTimePausedAt() !== null;

        return [
            'container_number' => $container->getContainerNumber(),
            'is_alerted' => $isAlerted,
            'is_dwell_time_paused' => $isPaused,
            'current_dwell_time' => $container->getCurrentDwellTime(),
            'paused_at' => $container->getDwellTimePausedAt()?->format('Y-m-d H:i:s'),
            'total_paused_days' => $container->getTotalPausedDays(),
            'next_notification_date' => $container->getNextNotificationDate()?->format('Y-m-d'),
            'automatic_return_date' => $container->getAutomaticReturnDate()?->format('Y-m-d'),
            'status' => $container->getStatus()->value
        ];
    }

    /**
     * Get active terminal team users
     */
    private function getActiveTerminalTeamUsers(): array
    {
        return $this->entityManager->getRepository(TerminalTeamUser::class)
            ->createQueryBuilder('ttu')
            ->where('ttu.status = :status')
            ->setParameter('status', \App\Entity\Enum\AccountStatus::APPROVED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Send email notification to terminal team
     */
    private function sendTerminalTeamEmail(array $terminalTeamUsers, string $subject, Container $container, array $data): void
    {
        foreach ($terminalTeamUsers as $terminalUser) {
            try {
                // Use existing email service to send notifications
                // This would be extended based on the email template structure
                $this->logger->info('Sending terminal team email notification', [
                    'user_id' => $terminalUser->getId(),
                    'user_email' => $terminalUser->getEmail(),
                    'subject' => $subject,
                    'container_number' => $container->getContainerNumber()
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send terminal team email', [
                    'user_id' => $terminalUser->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Format container data for dashboard display
     */
    private function formatContainerForDashboard(Container $container): array
    {
        return [
            'id' => $container->getId(),
            'container_number' => $container->getContainerNumber(),
            'current_dwell_time' => $container->getCurrentDwellTime(),
            'status' => $container->getStatus()->value,
            'is_paused' => $container->getDwellTimePausedAt() !== null,
            'paused_at' => $container->getDwellTimePausedAt()?->format('Y-m-d H:i:s'),
            'next_notification_date' => $container->getNextNotificationDate()?->format('Y-m-d'),
            'automatic_return_date' => $container->getAutomaticReturnDate()?->format('Y-m-d'),
            'terminal_arrival_date' => $container->getTerminalArrivalDate()?->format('Y-m-d')
        ];
    }

    /**
     * Format dwell time event for dashboard display
     */
    private function formatEventForDashboard(\App\Entity\DwellTimeEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'container_number' => $event->getContainer()->getContainerNumber(),
            'event_type' => $event->getEventType()->value,
            'event_date' => $event->getEventDate()->format('Y-m-d H:i:s'),
            'dwell_time_at_event' => $event->getDwellTimeAtEvent(),
            'reason' => $event->getReason(),
            'metadata' => $event->getMetadata()
        ];
    }
}
