<?php

namespace App\Twig;

use App\Repository\EDOPaymentRepository;
use App\Entity\Enum\PaymentStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EDOPaymentExtension extends AbstractExtension
{
    public function __construct(
        private EDOPaymentRepository $edoPaymentRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_edo_payment_count', [$this, 'getPendingEDOPaymentCount']),
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
}
