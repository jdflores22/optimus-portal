<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DwellTimeAuditService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get comprehensive audit trail for a container
     */
    public function getAuditTrail(Container $container, ?array $filters = null): array
    {
        $qb = $this->entityManager->getRepository(DwellTimeEvent::class)
            ->createQueryBuilder('e')
            ->where('e.container = :container')
            ->setParameter('container', $container);

        // Apply filters if provided
        if ($filters) {
            if (isset($filters['event_type'])) {
                $qb->andWhere('e.eventType = :eventType')
                   ->setParameter('eventType', $filters['event_type']);
            }

            if (isset($filters['from_date'])) {
                $qb->andWhere('e.eventDate >= :fromDate')
                   ->setParameter('fromDate', $filters['from_date']);
            }

            if (isset($filters['to_date'])) {
                $qb->andWhere('e.eventDate <= :toDate')
                   ->setParameter('toDate', $filters['to_date']);
            }

            if (isset($filters['triggered_by'])) {
                $qb->andWhere('e.triggeredBy = :triggeredBy')
                   ->setParameter('triggeredBy', $filters['triggered_by']);
            }
        }

        $qb->orderBy('e.eventDate', 'DESC');

        $events = $qb->getQuery()->getResult();

        return array_map(fn($event) => $this->serializeEvent($event), $events);
    }

    /**
     * Query dwell time events with advanced filtering
     */
    public function queryEvents(array $criteria): array
    {
        $qb = $this->entityManager->getRepository(DwellTimeEvent::class)
            ->createQueryBuilder('e')
            ->leftJoin('e.container', 'c');

        // Container filter
        if (isset($criteria['container_id'])) {
            $qb->andWhere('c.id = :containerId')
               ->setParameter('containerId', $criteria['container_id']);
        }

        // Container number filter
        if (isset($criteria['container_number'])) {
            $qb->andWhere('c.containerNumber LIKE :containerNumber')
               ->setParameter('containerNumber', '%' . $criteria['container_number'] . '%');
        }

        // Event type filter
        if (isset($criteria['event_type'])) {
            if (is_array($criteria['event_type'])) {
                $qb->andWhere('e.eventType IN (:eventTypes)')
                   ->setParameter('eventTypes', $criteria['event_type']);
            } else {
                $qb->andWhere('e.eventType = :eventType')
                   ->setParameter('eventType', $criteria['event_type']);
            }
        }

        // Date range filter
        if (isset($criteria['from_date'])) {
            $qb->andWhere('e.eventDate >= :fromDate')
               ->setParameter('fromDate', $criteria['from_date']);
        }

        if (isset($criteria['to_date'])) {
            $qb->andWhere('e.eventDate <= :toDate')
               ->setParameter('toDate', $criteria['to_date']);
        }

        // User filter
        if (isset($criteria['triggered_by'])) {
            $qb->andWhere('e.triggeredBy = :triggeredBy')
               ->setParameter('triggeredBy', $criteria['triggered_by']);
        }

        // Sorting
        $sortBy = $criteria['sort_by'] ?? 'eventDate';
        $sortOrder = $criteria['sort_order'] ?? 'DESC';
        $qb->orderBy('e.' . $sortBy, $sortOrder);

        // Pagination
        if (isset($criteria['limit'])) {
            $qb->setMaxResults($criteria['limit']);
        }

        if (isset($criteria['offset'])) {
            $qb->setFirstResult($criteria['offset']);
        }

        $events = $qb->getQuery()->getResult();

        return array_map(fn($event) => $this->serializeEvent($event), $events);
    }

    /**
     * Count events matching criteria
     */
    public function countEvents(array $criteria): int
    {
        $qb = $this->entityManager->getRepository(DwellTimeEvent::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->leftJoin('e.container', 'c');

        // Apply same filters as queryEvents
        if (isset($criteria['container_id'])) {
            $qb->andWhere('c.id = :containerId')
               ->setParameter('containerId', $criteria['container_id']);
        }

        if (isset($criteria['container_number'])) {
            $qb->andWhere('c.containerNumber LIKE :containerNumber')
               ->setParameter('containerNumber', '%' . $criteria['container_number'] . '%');
        }

        if (isset($criteria['event_type'])) {
            if (is_array($criteria['event_type'])) {
                $qb->andWhere('e.eventType IN (:eventTypes)')
                   ->setParameter('eventTypes', $criteria['event_type']);
            } else {
                $qb->andWhere('e.eventType = :eventType')
                   ->setParameter('eventType', $criteria['event_type']);
            }
        }

        if (isset($criteria['from_date'])) {
            $qb->andWhere('e.eventDate >= :fromDate')
               ->setParameter('fromDate', $criteria['from_date']);
        }

        if (isset($criteria['to_date'])) {
            $qb->andWhere('e.eventDate <= :toDate')
               ->setParameter('toDate', $criteria['to_date']);
        }

        if (isset($criteria['triggered_by'])) {
            $qb->andWhere('e.triggeredBy = :triggeredBy')
               ->setParameter('triggeredBy', $criteria['triggered_by']);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Generate dwell time report for a date range
     */
    public function generateReport(\DateTime $fromDate, \DateTime $toDate, ?array $filters = null): array
    {
        $criteria = [
            'from_date' => $fromDate,
            'to_date' => $toDate
        ];

        if ($filters) {
            $criteria = array_merge($criteria, $filters);
        }

        $events = $this->queryEvents($criteria);

        // Group events by type
        $eventsByType = [];
        foreach (DwellTimeEventType::cases() as $type) {
            $eventsByType[$type->value] = 0;
        }

        foreach ($events as $event) {
            $eventsByType[$event['event_type']]++;
        }

        // Calculate statistics
        $pauseEvents = array_filter($events, fn($e) => $e['event_type'] === DwellTimeEventType::PAUSE->value);
        $resumeEvents = array_filter($events, fn($e) => $e['event_type'] === DwellTimeEventType::RESUME->value);
        $notificationEvents = array_filter($events, fn($e) => $e['event_type'] === DwellTimeEventType::NOTIFICATION_60_DAY->value);
        $returnEvents = array_filter($events, fn($e) => $e['event_type'] === DwellTimeEventType::AUTOMATIC_RETURN->value);

        return [
            'date_range' => [
                'from' => $fromDate->format('Y-m-d H:i:s'),
                'to' => $toDate->format('Y-m-d H:i:s')
            ],
            'total_events' => count($events),
            'events_by_type' => $eventsByType,
            'statistics' => [
                'total_pauses' => count($pauseEvents),
                'total_resumes' => count($resumeEvents),
                'total_notifications' => count($notificationEvents),
                'total_automatic_returns' => count($returnEvents)
            ],
            'events' => $events
        ];
    }

    /**
     * Get pause/resume history for a container
     */
    public function getPauseResumeHistory(Container $container): array
    {
        $events = $this->queryEvents([
            'container_id' => $container->getId(),
            'event_type' => [DwellTimeEventType::PAUSE, DwellTimeEventType::RESUME],
            'sort_by' => 'eventDate',
            'sort_order' => 'ASC'
        ]);

        $history = [];
        $currentPause = null;

        foreach ($events as $event) {
            if ($event['event_type'] === DwellTimeEventType::PAUSE->value) {
                $currentPause = $event;
            } elseif ($event['event_type'] === DwellTimeEventType::RESUME->value && $currentPause) {
                $pauseDate = new \DateTime($currentPause['event_date']);
                $resumeDate = new \DateTime($event['event_date']);
                $duration = $pauseDate->diff($resumeDate)->days;

                $history[] = [
                    'pause_event' => $currentPause,
                    'resume_event' => $event,
                    'duration_days' => $duration
                ];

                $currentPause = null;
            }
        }

        // If there's an ongoing pause
        if ($currentPause) {
            $pauseDate = new \DateTime($currentPause['event_date']);
            $now = new \DateTime();
            $duration = $pauseDate->diff($now)->days;

            $history[] = [
                'pause_event' => $currentPause,
                'resume_event' => null,
                'duration_days' => $duration,
                'is_ongoing' => true
            ];
        }

        return $history;
    }

    /**
     * Get notification history for a container
     */
    public function getNotificationHistory(Container $container): array
    {
        return $this->queryEvents([
            'container_id' => $container->getId(),
            'event_type' => [
                DwellTimeEventType::NOTIFICATION_60_DAY,
                DwellTimeEventType::AUTOMATIC_RETURN
            ],
            'sort_by' => 'eventDate',
            'sort_order' => 'DESC'
        ]);
    }

    /**
     * Serialize a DwellTimeEvent for API responses
     */
    private function serializeEvent(DwellTimeEvent $event): array
    {
        $data = [
            'id' => $event->getId(),
            'container_id' => $event->getContainer()->getId(),
            'container_number' => $event->getContainer()->getContainerNumber(),
            'event_type' => $event->getEventType()->value,
            'event_date' => $event->getEventDate()->format('Y-m-d H:i:s'),
            'dwell_time_at_event' => $event->getDwellTimeAtEvent(),
            'reason' => $event->getReason(),
            'metadata' => $event->getMetadata()
        ];

        if ($event->getTriggeredBy()) {
            $data['triggered_by'] = [
                'id' => $event->getTriggeredBy()->getId(),
                'email' => $event->getTriggeredBy()->getEmail(),
                'role' => $event->getTriggeredBy()->getRole()->value
            ];
        }

        return $data;
    }
}
