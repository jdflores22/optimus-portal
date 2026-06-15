<?php

namespace App\Repository;

use App\Entity\Province;
use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Province>
 */
class ProvinceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Province::class);
    }

    public function findByRegion(Region $region): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.region = :region')
            ->setParameter('region', $region)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByRegionId(int $regionId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.region = :regionId')
            ->setParameter('regionId', $regionId)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
