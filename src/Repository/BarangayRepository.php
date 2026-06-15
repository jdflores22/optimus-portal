<?php

namespace App\Repository;

use App\Entity\Barangay;
use App\Entity\City;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Barangay>
 */
class BarangayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Barangay::class);
    }

    public function findByCity(City $city): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.city = :city')
            ->setParameter('city', $city)
            ->orderBy('b.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCityId(int $cityId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.city = :cityId')
            ->setParameter('cityId', $cityId)
            ->orderBy('b.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
