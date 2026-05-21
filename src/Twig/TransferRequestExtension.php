<?php

namespace App\Twig;

use App\Entity\BrokerTransferRequest;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class TransferRequestExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function getGlobals(): array
    {
        $pendingCount = $this->entityManager->getRepository(BrokerTransferRequest::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status = :status')
            ->setParameter('status', BrokerTransferRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'pendingTransferRequestsCount' => $pendingCount,
        ];
    }
}
