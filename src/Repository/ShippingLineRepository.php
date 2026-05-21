<?php

namespace App\Repository;

use App\Entity\ShippingLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingLine>
 */
class ShippingLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingLine::class);
    }

    /**
     * Find shipping line by brand name
     */
    public function findByBrandName(string $brandName): ?ShippingLine
    {
        return $this->findOneBy(['brandName' => $brandName]);
    }

    /**
     * Find all active shipping lines
     */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['brandName' => 'ASC']);
    }

    /**
     * Find shipping lines with active admins
     */
    public function findWithActiveAdmins(): array
    {
        return $this->createQueryBuilder('sl')
            ->innerJoin('sl.shippingLineAdmins', 'admin')
            ->where('sl.isActive = :active')
            ->andWhere('admin.status = :adminStatus')
            ->setParameter('active', true)
            ->setParameter('adminStatus', 'ACTIVE')
            ->orderBy('sl.brandName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find shipping lines without any admins
     */
    public function findWithoutAdmins(): array
    {
        return $this->createQueryBuilder('sl')
            ->leftJoin('sl.shippingLineAdmins', 'admin')
            ->where('admin.id IS NULL')
            ->andWhere('sl.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('sl.brandName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search shipping lines by brand name pattern
     */
    public function searchByBrandName(string $pattern): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.brandName LIKE :pattern')
            ->andWhere('sl.isActive = :active')
            ->setParameter('pattern', '%' . $pattern . '%')
            ->setParameter('active', true)
            ->orderBy('sl.brandName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get shipping line statistics
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('sl');
        
        return [
            'total' => $qb->select('COUNT(sl.id)')->getQuery()->getSingleScalarResult(),
            'active' => $qb->select('COUNT(sl.id)')
                ->where('sl.isActive = :active')
                ->setParameter('active', true)
                ->getQuery()
                ->getSingleScalarResult(),
            'with_admins' => $this->createQueryBuilder('sl2')
                ->select('COUNT(DISTINCT sl2.id)')
                ->innerJoin('sl2.shippingLineAdmins', 'admin')
                ->where('sl2.isActive = :active')
                ->setParameter('active', true)
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    public function save(ShippingLine $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ShippingLine $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}