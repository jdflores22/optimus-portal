<?php

namespace App\Twig;

use App\Entity\SuspensionAppeal;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class AppealExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function getGlobals(): array
    {
        $pendingCount = $this->entityManager->getRepository(SuspensionAppeal::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'pendingAppealsCount' => $pendingCount,
        ];
    }
}
