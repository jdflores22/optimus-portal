<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserShippingLinePreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserShippingLinePreference>
 */
class UserShippingLinePreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserShippingLinePreference::class);
    }

    /**
     * Find preference by user
     */
    public function findByUser(User $user): ?UserShippingLinePreference
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Create or update user preference
     */
    public function savePreference(UserShippingLinePreference $preference): void
    {
        $this->getEntityManager()->persist($preference);
        $this->getEntityManager()->flush();
    }

    /**
     * Delete user preference
     */
    public function deletePreference(UserShippingLinePreference $preference): void
    {
        $this->getEntityManager()->remove($preference);
        $this->getEntityManager()->flush();
    }
}
