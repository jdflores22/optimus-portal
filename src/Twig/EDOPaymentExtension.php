<?php

namespace App\Twig;

use App\Repository\EDOPaymentRepository;
use App\Repository\ElectronicDeliveryOrderRepository;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\EDOStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EDOPaymentExtension extends AbstractExtension
{
    public function __construct(
        private EDOPaymentRepository $edoPaymentRepository,
        private ElectronicDeliveryOrderRepository $edoRepository,
        private Security $security
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_edo_payment_count', [$this, 'getPendingEDOPaymentCount']),
            new TwigFunction('broker_pending_payment_edo_count', [$this, 'getBrokerPendingPaymentEDOCount']),
        ];
    }

    /**
     * Get count of EDO payments with pending_validation status
     */
    public function getPendingEDOPaymentCount(): int
    {
        return $this->edoPaymentRepository->createQueryBuilder('ep')
            ->select('COUNT(ep.id)')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get count of EDOs with pending_release status (unpaid) for current broker
     */
    public function getBrokerPendingPaymentEDOCount(): int
    {
        $user = $this->security->getUser();
        
        // Only calculate for brokers
        if (!$user || !in_array('ROLE_BROKER', $user->getRoles())) {
            return 0;
        }

        return $this->edoRepository->createQueryBuilder('edo')
            ->select('COUNT(edo.id)')
            ->leftJoin('edo.manifest', 'm')
            ->leftJoin('m.broker', 'b')
            ->where('b.id = :brokerId')
            ->andWhere('edo.status = :status')
            ->setParameter('brokerId', $user->getId())
            ->setParameter('status', EDOStatus::PENDING_RELEASE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
