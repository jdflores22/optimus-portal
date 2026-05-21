<?php

namespace App\Repository;

use App\Entity\City;
use App\Entity\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<City>
 */
class CityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, City::class);
    }

    public function findByRegion(Region $region): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.region = :region')
            ->setParameter('region', $region)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByRegionId(int $regionId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.region = :regionId')
            ->setParameter('regionId', $regionId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
