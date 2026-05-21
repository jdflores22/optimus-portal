<?php

namespace App\Repository;

use App\Entity\GenerationSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GenerationSession>
 */
class GenerationSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GenerationSession::class);
    }

    /**
     * Find a session by its session ID
     */
    public function findBySessionId(string $sessionId): ?GenerationSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    /**
     * Find all sessions for a specific manifest
     */
    public function findByManifest(int $manifestId): array
    {
        return $this->createQueryBuilder('gs')
            ->where('gs.manifest = :manifestId')
            ->setParameter('manifestId', $manifestId)
            ->orderBy('gs.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active (in_progress) sessions
     */
    public function findActiveSessions(): array
    {
        return $this->createQueryBuilder('gs')
            ->where('gs.status = :status')
            ->setParameter('status', 'in_progress')
            ->orderBy('gs.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
