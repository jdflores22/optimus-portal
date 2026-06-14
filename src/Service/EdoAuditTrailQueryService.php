<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\AuditEventType;
use App\Entity\User;
use App\Repository\ContainerRepository;
use App\Repository\ElectronicDeliveryOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

class EdoAuditTrailQueryService
{
    private const ACTION_EVENT_MAP = [
        'edo_generated' => 'edo_created',
        'edo_payment_submission' => 'payment_submitted',
        'edo_payment_submitted' => 'payment_submitted',
        'edo_payment_approved' => 'payment_confirmed',
        'edo_payment_rejected' => 'payment_rejected',
        'edo_renewal_requested' => 'regeneration_requested',
        'edo_renewal_request_created' => 'regeneration_requested',
        'billing_generated' => 'billing_generated',
        'admin_unlocked' => 'admin_unlocked',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private ContainerRepository $containerRepository
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchByEdoNumber(string $edoNumber): array
    {
        $edoNumber = trim($edoNumber);
        if ($edoNumber === '') {
            return [];
        }

        $edo = $this->edoRepository->findByNumberWithRelations($edoNumber);
        $edoIds = $edo ? [$edo->getId()] : [];

        $logs = $this->fetchAuditLogs($edoNumber, null, $edoIds);

        return $this->formatLogs($logs, $edo);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchByContainerNumber(string $containerNumber): array
    {
        $containerNumber = trim($containerNumber);
        if ($containerNumber === '') {
            return [];
        }

        $container = $this->containerRepository->findByContainerNumber($containerNumber);
        $edos = $container
            ? $this->edoRepository->findByContainerWithRelations($container->getId())
            : [];

        $edoIds = array_map(static fn (ElectronicDeliveryOrder $edo) => $edo->getId(), $edos);
        $logs = $this->fetchAuditLogs(null, $containerNumber, $edoIds);

        return $this->formatLogs($logs, null, $container, $edos);
    }

    /**
     * @param int[] $edoIds
     * @return AuditLog[]
     */
    private function fetchAuditLogs(?string $edoNumber, ?string $containerNumber, array $edoIds): array
    {
        $qb = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->leftJoin('a.relatedEdo', 're')
            ->addSelect('re')
            ->orderBy('a.timestamp', 'DESC');

        $conditions = [];
        $parameters = [
            'edoType' => 'ElectronicDeliveryOrder',
            'paymentType' => 'EDOPayment',
        ];

        if ($edoIds !== []) {
            $conditions[] = '(a.entityType = :edoType AND a.entityId IN (:edoIds))';
            $conditions[] = 're.id IN (:edoIds)';
            $parameters['edoIds'] = $edoIds;
        }

        if ($edoNumber !== null && $edoNumber !== '') {
            $conditions[] = 'a.changes LIKE :edoNumberPattern';
            $parameters['edoNumberPattern'] = '%"edo_number":"' . $this->escapeLike($edoNumber) . '"%';
        }

        if ($containerNumber !== null && $containerNumber !== '') {
            $conditions[] = 'a.changes LIKE :containerPattern';
            $parameters['containerPattern'] = '%"container_number":"' . $this->escapeLike($containerNumber) . '"%';
        }

        if ($conditions === []) {
            return [];
        }

        $qb->where(implode(' OR ', $conditions));

        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        /** @var AuditLog[] $logs */
        $logs = $qb->getQuery()->getResult();

        return $this->dedupeLogs($logs);
    }

    /**
     * @param AuditLog[] $logs
     * @param ElectronicDeliveryOrder[] $edosByContainer
     * @return array<int, array<string, mixed>>
     */
    private function formatLogs(
        array $logs,
        ?ElectronicDeliveryOrder $primaryEdo = null,
        ?Container $container = null,
        array $edosByContainer = []
    ): array {
        $edoCache = [];

        if ($primaryEdo) {
            $edoCache[$primaryEdo->getId()] = $primaryEdo;
        }

        foreach ($edosByContainer as $edo) {
            $edoCache[$edo->getId()] = $edo;
        }

        $formatted = [];

        foreach ($logs as $log) {
            $changes = $log->getChanges();
            $edo = $this->resolveEdoForLog($log, $edoCache);
            $edoNumber = $changes['edo_number'] ?? $edo?->getEdoNumber() ?? 'N/A';
            $containerNumber = $changes['container_number']
                ?? $edo?->getContainer()?->getContainerNumber()
                ?? $container?->getContainerNumber()
                ?? 'N/A';

            $formatted[] = [
                'event_type' => $this->mapActionToEventType($log->getAction()),
                'edo_number' => $edoNumber,
                'container_number' => $containerNumber,
                'timestamp' => $log->getTimestamp()->format(\DateTimeInterface::ATOM),
                'user' => $this->formatUser($log->getUser()),
                'details' => $this->buildDetails($log, $changes),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, ElectronicDeliveryOrder> $edoCache
     */
    private function resolveEdoForLog(AuditLog $log, array &$edoCache): ?ElectronicDeliveryOrder
    {
        if ($log->getRelatedEdo()) {
            $edo = $log->getRelatedEdo();
            $edoCache[$edo->getId()] = $edo;

            return $edo;
        }

        if ($log->getEntityType() === 'ElectronicDeliveryOrder') {
            $edoId = $log->getEntityId();

            if (!isset($edoCache[$edoId])) {
                $edoCache[$edoId] = $this->edoRepository->findWithRelations($edoId);
            }

            return $edoCache[$edoId];
        }

        $changes = $log->getChanges();
        if (isset($changes['edo_id'])) {
            $edoId = (int) $changes['edo_id'];

            if (!isset($edoCache[$edoId])) {
                $edoCache[$edoId] = $this->edoRepository->findWithRelations($edoId);
            }

            return $edoCache[$edoId];
        }

        if (isset($changes['edo_number'])) {
            $edoNumber = (string) $changes['edo_number'];
            foreach ($edoCache as $edo) {
                if ($edo && $edo->getEdoNumber() === $edoNumber) {
                    return $edo;
                }
            }

            $edo = $this->edoRepository->findByNumberWithRelations($edoNumber);
            if ($edo) {
                $edoCache[$edo->getId()] = $edo;
            }

            return $edo;
        }

        return null;
    }

    private function mapActionToEventType(string $action): string
    {
        if (isset(self::ACTION_EVENT_MAP[$action])) {
            return self::ACTION_EVENT_MAP[$action];
        }

        foreach (AuditEventType::cases() as $eventType) {
            if ($eventType->value === $action) {
                return $action;
            }
        }

        return $action;
    }

    /**
     * @return array{email: string, full_name: string}
     */
    private function formatUser(User $user): array
    {
        $roleLabel = ucwords(strtolower(str_replace('_', ' ', $user->getRole()->value)));

        return [
            'email' => $user->getEmail(),
            'full_name' => $roleLabel,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function buildDetails(AuditLog $log, array $changes): array
    {
        $details = $changes;
        $details['action'] = $log->getAction();
        $details['entity_type'] = $log->getEntityType();
        $details['entity_id'] = $log->getEntityId();
        $details['ip_address'] = $log->getIpAddress();

        return $details;
    }

    /**
     * @param AuditLog[] $logs
     * @return AuditLog[]
     */
    private function dedupeLogs(array $logs): array
    {
        $unique = [];

        foreach ($logs as $log) {
            $unique[$log->getId()] = $log;
        }

        return array_values($unique);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
