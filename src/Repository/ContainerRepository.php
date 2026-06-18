<?php

namespace App\Repository;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Container>
 */
class ContainerRepository extends ServiceEntityRepository
{
    private const NORMALIZED_CONTAINER_NUMBER_SQL = "REPLACE(REPLACE(REPLACE(UPPER(container_number), ' ', ''), '-', ''), '_', '')";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Container::class);
    }

    public static function normalizeContainerNumber(string $containerNumber): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($containerNumber))) ?? '';
    }

    /**
     * @return array{id: int|string, container_number: string}|null
     */
    public function findOneInventoryMatchByNormalizedNumber(string $normalizedNumber): ?array
    {
        if ($normalizedNumber === '') {
            return null;
        }

        $connection = $this->getEntityManager()->getConnection();
        $sql = sprintf(
            'SELECT id, container_number FROM containers WHERE %s = :normalized LIMIT 1',
            self::NORMALIZED_CONTAINER_NUMBER_SQL
        );

        $result = $connection->executeQuery($sql, ['normalized' => $normalizedNumber])->fetchAssociative();

        return $result ?: null;
    }

    /**
     * @param list<string> $normalizedNumbers
     * @return list<string>
     */
    public function findInventoryMatchesByNormalizedNumbers(array $normalizedNumbers): array
    {
        $normalizedNumbers = array_values(array_filter(array_unique($normalizedNumbers)));
        if ($normalizedNumbers === []) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $placeholders = implode(', ', array_fill(0, count($normalizedNumbers), '?'));
        $sql = sprintf(
            'SELECT container_number FROM containers WHERE %s IN (%s)',
            self::NORMALIZED_CONTAINER_NUMBER_SQL,
            $placeholders
        );

        return $connection->executeQuery($sql, $normalizedNumbers)->fetchFirstColumn();
    }

    /**
     * Find container by container number
     */
    public function findByContainerNumber(string $containerNumber): ?Container
    {
        return $this->findOneBy(['containerNumber' => $containerNumber]);
    }

    /**
     * Find containers available for return
     */
    public function findAvailableForReturn(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', ContainerStatus::AVAILABLE_FOR_RETURN)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if container is available for return by container number
     */
    public function isAvailableForReturn(string $containerNumber): bool
    {
        $container = $this->findByContainerNumber($containerNumber);
        
        return $container !== null && $container->getStatus() === ContainerStatus::AVAILABLE_FOR_RETURN;
    }

    /**
     * Find containers by status
     */
    public function findByStatus(ContainerStatus $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /**
     * Find containers by type and size
     */
    public function findByTypeAndSize(string $type, string $size): array
    {
        return $this->findBy(['type' => $type, 'size' => $size]);
    }
}