<?php

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AuditLogRetentionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Archive old audit logs to file storage
     * This should be run periodically (e.g., monthly via cron job)
     * 
     * @return array Statistics about archived logs
     */
    public function archiveOldLogs(): array
    {
        $archiveAfterDays = $this->params->get('audit_log.archive_after_days');
        $archivePath = $this->params->get('audit_log.archive_path');
        
        if (!$this->params->get('audit_log.archive_enabled')) {
            $this->logger->info('Audit log archival is disabled');
            return ['archived' => 0, 'message' => 'Archival disabled'];
        }

        // Calculate cutoff date
        $cutoffDate = new \DateTime();
        $cutoffDate->modify("-{$archiveAfterDays} days");

        // Find logs older than cutoff date that haven't been archived
        $qb = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.timestamp < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->orderBy('a.timestamp', 'ASC');

        $logsToArchive = $qb->getQuery()->getResult();
        
        if (empty($logsToArchive)) {
            $this->logger->info('No audit logs to archive');
            return ['archived' => 0, 'message' => 'No logs to archive'];
        }

        // Create archive directory if it doesn't exist
        if (!is_dir($archivePath)) {
            mkdir($archivePath, 0755, true);
        }

        // Group logs by year-month for archival
        $logsByMonth = [];
        foreach ($logsToArchive as $log) {
            $monthKey = $log->getTimestamp()->format('Y-m');
            if (!isset($logsByMonth[$monthKey])) {
                $logsByMonth[$monthKey] = [];
            }
            $logsByMonth[$monthKey][] = $log;
        }

        $archivedCount = 0;
        
        // Archive each month's logs
        foreach ($logsByMonth as $monthKey => $logs) {
            $archiveFile = $archivePath . '/audit_logs_' . $monthKey . '.json';
            
            // Convert logs to array format
            $logsData = array_map(function($log) {
                $user = $log->getUser();
                return [
                    'id' => $log->getId(),
                    'timestamp' => $log->getTimestamp()->format('Y-m-d H:i:s'),
                    'user_id' => $user->getId(),
                    'user_name' => $user->getFullName() ?? $user->getEmail(),
                    'user_role' => $user->getRole()->value,
                    'action' => $log->getAction(),
                    'entity_type' => $log->getEntityType(),
                    'entity_id' => $log->getEntityId(),
                    'changes' => $log->getChanges(),
                    'ip_address' => $log->getIpAddress(),
                ];
            }, $logs);

            // Write to archive file
            $existingData = [];
            if (file_exists($archiveFile)) {
                $existingData = json_decode(file_get_contents($archiveFile), true) ?? [];
            }
            
            $allData = array_merge($existingData, $logsData);
            file_put_contents(
                $archiveFile, 
                json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            $archivedCount += count($logs);
            
            $this->logger->info("Archived {count} audit logs for {month}", [
                'count' => count($logs),
                'month' => $monthKey,
                'file' => $archiveFile
            ]);
        }

        return [
            'archived' => $archivedCount,
            'months' => count($logsByMonth),
            'message' => "Successfully archived {$archivedCount} logs"
        ];
    }

    /**
     * Delete audit logs older than retention period
     * WARNING: This permanently deletes logs. Ensure archival is complete first.
     * 
     * @return array Statistics about deleted logs
     */
    public function deleteExpiredLogs(): array
    {
        $retentionDays = $this->params->get('audit_log.retention_days');
        
        // Calculate cutoff date
        $cutoffDate = new \DateTime();
        $cutoffDate->modify("-{$retentionDays} days");

        // Find logs older than retention period
        $qb = $this->entityManager->getRepository(AuditLog::class)
            ->createQueryBuilder('a')
            ->where('a.timestamp < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate);

        $logsToDelete = $qb->getQuery()->getResult();
        
        if (empty($logsToDelete)) {
            $this->logger->info('No expired audit logs to delete');
            return ['deleted' => 0, 'message' => 'No expired logs'];
        }

        $deleteCount = count($logsToDelete);
        
        // Delete logs in batches
        $batchSize = 100;
        $i = 0;
        foreach ($logsToDelete as $log) {
            $this->entityManager->remove($log);
            
            if (($i % $batchSize) === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
            $i++;
        }
        
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->logger->warning("Deleted {count} expired audit logs older than {date}", [
            'count' => $deleteCount,
            'date' => $cutoffDate->format('Y-m-d'),
            'retention_days' => $retentionDays
        ]);

        return [
            'deleted' => $deleteCount,
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'message' => "Deleted {$deleteCount} expired logs"
        ];
    }

    /**
     * Get retention policy information
     * 
     * @return array Retention policy details
     */
    public function getRetentionPolicy(): array
    {
        return [
            'retention_years' => $this->params->get('audit_log.retention_years'),
            'retention_days' => $this->params->get('audit_log.retention_days'),
            'archive_enabled' => $this->params->get('audit_log.archive_enabled'),
            'archive_after_days' => $this->params->get('audit_log.archive_after_days'),
            'archive_path' => $this->params->get('audit_log.archive_path'),
        ];
    }

    /**
     * Get statistics about current audit logs
     * 
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        $retentionDays = $this->params->get('audit_log.retention_days');
        $archiveAfterDays = $this->params->get('audit_log.archive_after_days');
        
        $now = new \DateTime();
        $retentionCutoff = (clone $now)->modify("-{$retentionDays} days");
        $archiveCutoff = (clone $now)->modify("-{$archiveAfterDays} days");

        $repo = $this->entityManager->getRepository(AuditLog::class);

        // Total logs
        $totalLogs = $repo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Logs eligible for archival
        $eligibleForArchival = $repo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.timestamp < :archiveCutoff')
            ->setParameter('archiveCutoff', $archiveCutoff)
            ->getQuery()
            ->getSingleScalarResult();

        // Logs eligible for deletion
        $eligibleForDeletion = $repo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.timestamp < :retentionCutoff')
            ->setParameter('retentionCutoff', $retentionCutoff)
            ->getQuery()
            ->getSingleScalarResult();

        // Oldest log
        $oldestLog = $repo->createQueryBuilder('a')
            ->orderBy('a.timestamp', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'total_logs' => (int) $totalLogs,
            'eligible_for_archival' => (int) $eligibleForArchival,
            'eligible_for_deletion' => (int) $eligibleForDeletion,
            'oldest_log_date' => $oldestLog ? $oldestLog->getTimestamp()->format('Y-m-d H:i:s') : null,
            'retention_cutoff_date' => $retentionCutoff->format('Y-m-d'),
            'archive_cutoff_date' => $archiveCutoff->format('Y-m-d'),
        ];
    }
}
