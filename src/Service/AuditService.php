<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\EDOPayment;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private StructuredLogger $structuredLogger
    ) {
    }

    /**
     * Log a state-changing action
     * 
     * @param User $user The user performing the action
     * @param string $action The action being performed (e.g., "status_change", "approval", "denial")
     * @param string $entityType The type of entity being modified (e.g., "AccreditationSubmission", "ShipmentRecord")
     * @param int $entityId The ID of the entity being modified
     * @param array $changes Array of changes made (e.g., ['status' => ['from' => 'PENDING', 'to' => 'APPROVED']])
     * @return AuditLog The created audit log entry
     */
    public function logAction(
        User $user,
        string $action,
        string $entityType,
        int $entityId,
        array $changes
    ): AuditLog {
        $auditLog = new AuditLog();
        $auditLog->setUser($this->resolveManagedUser($user));
        $auditLog->setAction($action);
        $auditLog->setEntityType($entityType);
        $auditLog->setEntityId($entityId);
        $auditLog->setChanges($changes);
        $auditLog->setIpAddress($this->getClientIp());

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        // Log to structured audit log
        $this->structuredLogger->logAuditEvent($action, $entityType, $entityId, $changes, $user);

        return $auditLog;
    }

    /**
     * Log resource access (e.g., viewing an EDO)
     * 
     * @param User $user The user accessing the resource
     * @param string $resource The resource type being accessed (e.g., "EDO", "ShipmentRecord")
     * @param int $resourceId The ID of the resource being accessed
     * @return AuditLog The created audit log entry
     */
    public function logAccess(User $user, string $resource, int $resourceId): AuditLog
    {
        $auditLog = new AuditLog();
        $auditLog->setUser($this->resolveManagedUser($user));
        $auditLog->setAction('access');
        $auditLog->setEntityType($resource);
        $auditLog->setEntityId($resourceId);
        $auditLog->setChanges([]);
        $auditLog->setIpAddress($this->getClientIp());

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        // Log to structured audit log
        $this->structuredLogger->logAuditEvent('access', $resource, $resourceId, [], $user);

        return $auditLog;
    }

    /**
     * Get audit trail for a specific entity
     * 
     * @param string $entityType The entity type
     * @param int $entityId The entity ID
     * @return array Array of AuditLog entries ordered by timestamp descending
     */
    public function getAuditTrail(string $entityType, int $entityId): array
    {
        return $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search audit logs with filtering
     * 
     * @param array $criteria Search criteria with optional keys:
     *                        - 'startDate': \DateTimeInterface
     *                        - 'endDate': \DateTimeInterface
     *                        - 'userId': int
     *                        - 'action': string
     *                        - 'entityType': string
     * @return array Array of AuditLog entries matching criteria
     */
    public function searchLogs(array $criteria): array
    {
        $qb = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a');

        if (isset($criteria['startDate'])) {
            $qb->andWhere('a.timestamp >= :startDate')
               ->setParameter('startDate', $criteria['startDate']);
        }

        if (isset($criteria['endDate'])) {
            $qb->andWhere('a.timestamp <= :endDate')
               ->setParameter('endDate', $criteria['endDate']);
        }

        if (isset($criteria['userId'])) {
            $qb->andWhere('a.user = :userId')
               ->setParameter('userId', $criteria['userId']);
        }

        if (isset($criteria['action'])) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $criteria['action']);
        }

        if (isset($criteria['entityType'])) {
            $qb->andWhere('a.entityType = :entityType')
               ->setParameter('entityType', $criteria['entityType']);
        }

        $qb->orderBy('a.timestamp', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Log state transition for manifest workflow
     * 
     * @param int $manifestId The manifest ID
     * @param string $fromState The previous state
     * @param string $toState The new state
     * @param User $actor The user performing the transition
     * @param string|null $reason Optional reason for transition
     */
    public function logStateTransition(
        int $manifestId,
        string $fromState,
        string $toState,
        User $actor,
        ?string $reason = null
    ): void {
        $this->logAction(
            $actor,
            'state_transition',
            'Manifest',
            $manifestId,
            [
                'from_state' => $fromState,
                'to_state' => $toState,
                'reason' => $reason
            ]
        );
    }

    /**
     * Log payment verification action
     * 
     * @param int $paymentId The payment ID
     * @param User $validator The user validating the payment
     * @param bool $approved Whether payment was approved
     * @param string|null $reason Reason for rejection if not approved
     */
    public function logPaymentVerification(
        int $paymentId,
        User $validator,
        bool $approved,
        ?string $reason = null
    ): void {
        $this->logAction(
            $validator,
            'payment_verification',
            'Payment',
            $paymentId,
            [
                'approved' => $approved,
                'reason' => $reason
            ]
        );
    }

    /**
     * Log document upload action
     * 
     * @param User $user The user uploading the document
     * @param string $documentType Type of document (e.g., 'BL', 'receipt')
     * @param int $entityId The related entity ID
     * @param string $filename The uploaded filename
     */
    public function logDocumentUpload(
        User $user,
        string $documentType,
        int $entityId,
        string $filename
    ): void {
        $this->logAction(
            $user,
            'document_upload',
            $documentType,
            $entityId,
            [
                'filename' => $filename,
                'upload_time' => date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Log document download action
     * 
     * @param User $user The user downloading the document
     * @param string $documentType Type of document (e.g., 'NOA', 'Billing', 'EDO')
     * @param int $documentId The document ID
     */
    public function logDocumentDownload(
        User $user,
        string $documentType,
        int $documentId
    ): void {
        $this->logAction(
            $user,
            'document_download',
            $documentType,
            $documentId,
            [
                'download_time' => date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Get audit trail for a specific manifest
     * 
     * @param int $manifestId The manifest ID
     * @return array Array of audit log entries
     */
    public function getManifestAuditTrail(int $manifestId): array
    {
        return $this->getAuditTrail('Manifest', $manifestId);
    }

    /**
     * Get complete audit trail for a manifest including eDO release history
     * Requirement 12.5: Allow authorized users to view complete audit trail for any manifest including eDO release history
     * 
     * @param int $manifestId The manifest ID
     * @return array Array containing both manifest audit logs and eDO release history
     */
    public function getManifestAuditTrailWithEDO(int $manifestId): array
    {
        // Get manifest audit logs
        $manifestLogs = $this->getAuditTrail('Manifest', $manifestId);
        
        // Get eDO audit logs for this manifest
        $edoLogs = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.changes LIKE :manifestId')
            ->setParameter('entityType', 'ElectronicDeliveryOrder')
            ->setParameter('manifestId', '%"manifest_id":' . $manifestId . '%')
            ->orderBy('a.timestamp', 'DESC')
            ->getQuery()
            ->getResult();

        $paymentLogs = $this->getPaymentAuditLogsForManifest($manifestId);
        $edoPaymentLogs = $this->getEdoPaymentAuditLogsForManifest($manifestId);
        
        // Combine and sort by timestamp
        $allLogs = array_merge($manifestLogs, $edoLogs, $paymentLogs, $edoPaymentLogs);
        $allLogs = $this->dedupeAuditLogs($allLogs);
        usort($allLogs, function($a, $b) {
            return $b->getTimestamp() <=> $a->getTimestamp();
        });
        
        return $allLogs;
    }

    /**
     * Final payment audit entries linked to a manifest (by changes JSON or Payment relation).
     *
     * @return AuditLog[]
     */
    private function getPaymentAuditLogsForManifest(int $manifestId): array
    {
        $repo = $this->entityManager->getRepository(AuditLog::class);

        $byManifestIdInChanges = $repo->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.changes LIKE :manifestId')
            ->setParameter('entityType', 'Payment')
            ->setParameter('manifestId', '%"manifest_id":' . $manifestId . '%')
            ->getQuery()
            ->getResult();

        $byPaymentRelation = $repo->createQueryBuilder('a')
            ->innerJoin(Payment::class, 'p', 'WITH', 'a.entityType = :entityType AND a.entityId = p.id')
            ->where('p.manifest = :manifestId')
            ->setParameter('entityType', 'Payment')
            ->setParameter('manifestId', $manifestId)
            ->getQuery()
            ->getResult();

        return $this->dedupeAuditLogs(array_merge($byManifestIdInChanges, $byPaymentRelation));
    }

    /**
     * eDO access payment audit entries for a manifest.
     *
     * @return AuditLog[]
     */
    private function getEdoPaymentAuditLogsForManifest(int $manifestId): array
    {
        return $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->innerJoin(EDOPayment::class, 'ep', 'WITH', 'a.entityType = :entityType AND a.entityId = ep.id')
            ->where('ep.manifest = :manifestId')
            ->setParameter('entityType', 'EDOPayment')
            ->setParameter('manifestId', $manifestId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param AuditLog[] $logs
     * @return AuditLog[]
     */
    private function dedupeAuditLogs(array $logs): array
    {
        $unique = [];
        foreach ($logs as $log) {
            $unique[$log->getId()] = $log;
        }

        return array_values($unique);
    }

    /**
     * Get the client IP address from the current request
     * 
     * @return string The client IP address or '0.0.0.0' if not available
     */
    private function getClientIp(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        
        if (!$request) {
            return '0.0.0.0';
        }

        return $request->getClientIp() ?? '0.0.0.0';
    }

    private function resolveManagedUser(User $user): User
    {
        if ($this->entityManager->contains($user)) {
            return $user;
        }

        return $this->entityManager->getReference(User::class, $user->getId());
    }
}
