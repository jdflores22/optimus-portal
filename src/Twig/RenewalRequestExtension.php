<?php

namespace App\Twig;

use App\Entity\EDORenewalRequest;
use App\Entity\Billing;
use App\Entity\Enum\RenewalRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class RenewalRequestExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();
        
        if (!$user) {
            return [
                'pendingRenewalRequestsCount' => 0,
                'detentionPaymentsCount' => 0,
                'readyForGenerationCount' => 0,
                'pendingEdoPaymentsCount' => 0,
                'manifestsReadyForEdoCount' => 0,
            ];
        }

        $userRole = $user->getRole()->value;
        
        // Only calculate counts for accounting and SL staff users
        if ($userRole !== 'ACCOUNTING' && $userRole !== 'SL_STAFF') {
            return [
                'pendingRenewalRequestsCount' => 0,
                'detentionPaymentsCount' => 0,
                'readyForGenerationCount' => 0,
                'pendingEdoPaymentsCount' => 0,
                'manifestsReadyForEdoCount' => 0,
            ];
        }

        // Get shipping line scope
        $shippingLine = $user->getShippingLineScope();
        
        if (!$shippingLine) {
            return [
                'pendingRenewalRequestsCount' => 0,
                'detentionPaymentsCount' => 0,
                'readyForGenerationCount' => 0,
                'pendingEdoPaymentsCount' => 0,
                'manifestsReadyForEdoCount' => 0,
            ];
        }

        $globals = [
            'pendingRenewalRequestsCount' => 0,
            'detentionPaymentsCount' => 0,
            'readyForGenerationCount' => 0,
            'pendingEdoPaymentsCount' => 0,
            'manifestsReadyForEdoCount' => 0,
        ];

        // Counts for ACCOUNTING role
        if ($userRole === 'ACCOUNTING') {
            // Count renewal requests with PENDING_REVIEW status
            $globals['pendingRenewalRequestsCount'] = $this->entityManager->getRepository(EDORenewalRequest::class)
                ->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->leftJoin('r.expiredEdo', 'e')
                ->leftJoin('e.shippingLine', 's')
                ->where('r.status = :status')
                ->andWhere('s.id = :shippingLineId')
                ->setParameter('status', RenewalRequestStatus::PENDING_REVIEW)
                ->setParameter('shippingLineId', $shippingLine->getId())
                ->getQuery()
                ->getSingleScalarResult();

            // Count detention billings awaiting payment verification (has receipt uploaded)
            $globals['detentionPaymentsCount'] = $this->entityManager->getRepository(Billing::class)
                ->createQueryBuilder('b')
                ->select('COUNT(b.id)')
                ->leftJoin('b.edoRenewalRequest', 'r')
                ->leftJoin('r.expiredEdo', 'e')
                ->leftJoin('e.shippingLine', 's')
                ->where('b.billingType = :type')
                ->andWhere('b.receiptFilePath IS NOT NULL')
                ->andWhere('r.paymentVerified = :verified')
                ->andWhere('s.id = :shippingLineId')
                ->setParameter('type', 'detention')
                ->setParameter('verified', false)
                ->setParameter('shippingLineId', $shippingLine->getId())
                ->getQuery()
                ->getSingleScalarResult();
            
            // Count pending EDO payments (Final Payment Validation)
            $globals['pendingEdoPaymentsCount'] = $this->entityManager->getRepository(\App\Entity\Payment::class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->where('p.paymentType = :type')
                ->andWhere('p.status = :status')
                ->setParameter('type', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
                ->setParameter('status', \App\Entity\Enum\PaymentStatus::PENDING_VALIDATION)
                ->getQuery()
                ->getSingleScalarResult();
        }

        // Counts for SL_STAFF role
        if ($userRole === 'SL_STAFF') {
            // Count renewal requests ready for eDO generation (payment verified or no payment required)
            $globals['readyForGenerationCount'] = $this->entityManager->getRepository(EDORenewalRequest::class)
                ->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->leftJoin('r.expiredEdo', 'e')
                ->leftJoin('e.shippingLine', 's')
                ->where('r.status IN (:statuses)')
                ->andWhere('s.id = :shippingLineId')
                ->andWhere('r.newEdo IS NULL')
                ->setParameter('statuses', [
                    RenewalRequestStatus::READY_FOR_GENERATION,
                    RenewalRequestStatus::PAYMENT_VERIFIED
                ])
                ->setParameter('shippingLineId', $shippingLine->getId())
                ->getQuery()
                ->getSingleScalarResult();
            
            // Count containers ready for eDO generation (payment verified, no eDO yet)
            $globals['manifestsReadyForEdoCount'] = $this->entityManager->createQuery(
                'SELECT COUNT(DISTINCT c.id)
                FROM App\Entity\Container c
                JOIN c.manifest m
                JOIN m.payments p
                JOIN m.shippingLine s
                WHERE m.workflowState = :state
                AND p.paymentType = :paymentType
                AND p.status = :paymentStatus
                AND s.id = :shippingLineId
                AND NOT EXISTS (
                    SELECT 1 FROM App\Entity\ElectronicDeliveryOrder e
                    WHERE e.container = c
                    AND e.status IN (:edoStatuses)
                )'
            )
            ->setParameter('state', \App\Entity\Enum\WorkflowState::PAYMENT_VERIFIED)
            ->setParameter('paymentType', \App\Entity\Enum\PaymentType::FINAL_PAYMENT)
            ->setParameter('paymentStatus', \App\Entity\Enum\PaymentStatus::VERIFIED)
            ->setParameter('shippingLineId', $shippingLine->getId())
            ->setParameter('edoStatuses', [\App\Entity\Enum\EDOStatus::PENDING_RELEASE, \App\Entity\Enum\EDOStatus::RELEASED])
            ->getSingleScalarResult();
        }

        return $globals;
    }
}
