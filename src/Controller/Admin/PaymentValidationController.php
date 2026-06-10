<?php

namespace App\Controller\Admin;

use App\Service\PaymentService;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/payment-validation')]
class PaymentValidationController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private EntityManagerInterface $entityManager,
        private \App\Service\EDOReleaseServiceInterface $edoReleaseService,
        private \App\Service\EDOPaymentServiceInterface $edoPaymentService
    ) {
    }

    #[Route('/dashboard', name: 'admin_payment_validation_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_ACCOUNTING')]
    public function dashboard(Request $request): Response
    {
        // Get pending EDO payments
        $edoPayments = $this->edoPaymentService->getPendingEDOAccessPayments();

        // Get statistics
        $stats = $this->getPaymentStatistics();

        return $this->render('admin/payment_validation/dashboard.html.twig', [
            'edoPayments' => $edoPayments,
            'stats' => $stats,
        ]);
    }

    #[Route('/final-payment', name: 'admin_payment_validation_final_payment', methods: ['GET'])]
    #[IsGranted('ROLE_ACCOUNTING')]
    public function finalPayments(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Get final payments
        $qb = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->leftJoin('m.billing', 'b')
            ->leftJoin('p.submittedBy', 'u')
            ->leftJoin('p.validatedBy', 'v')
            ->where('p.paymentType = :type')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->orderBy('p.createdAt', 'DESC');

        // Filter by status (default to pending)
        $statusFilter = $request->query->get('status', 'pending_validation');
        if ($statusFilter) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $statusFilter);
        }

        // Pagination
        $totalQuery = clone $qb;
        $total = count($totalQuery->getQuery()->getResult());
        $totalPages = ceil($total / $limit);

        $payments = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/payment_validation/final_payment.html.twig', [
            'payments' => $payments,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'statusFilter' => $statusFilter,
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    #[Route('/final-payment/{id}', name: 'admin_payment_validation_final_payment_detail', methods: ['GET'])]
    #[IsGranted('ROLE_ACCOUNTING')]
    public function finalPaymentDetail(int $id): Response
    {
        $payment = $this->entityManager->getRepository(\App\Entity\Payment::class)->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        if ($payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw $this->createNotFoundException('Invalid payment type');
        }

        // Get billing for comparison
        $billing = $payment->getManifest()->getBilling();
        if (!$billing) {
            throw $this->createNotFoundException('Billing not found for this manifest');
        }

        // Calculate amount discrepancy (compare same currencies)
        if ($payment->getCurrency() === 'USD' && $billing->getOriginalCurrency() === 'USD') {
            // Both are USD, compare USD amounts
            $discrepancy = abs($payment->getAmount() - $billing->getTotalAmountUsd());
        } else {
            // Compare PHP amounts (either both PHP or payment is PHP)
            $discrepancy = abs($payment->getAmount() - $billing->getTotalAmount());
        }

        // Get validation history for this manifest
        $validationHistory = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->andWhere('p.status != :pending')
            ->setParameter('manifest', $payment->getManifest())
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('pending', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('p.validatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/payment_validation/final_payment_detail.html.twig', [
            'payment' => $payment,
            'billing' => $billing,
            'discrepancy' => $discrepancy,
            'validationHistory' => $validationHistory,
        ]);
    }

    private function getPaymentStatistics(): array
    {
        $paymentRepo = $this->entityManager->getRepository(\App\Entity\Payment::class);
        $edoPaymentRepo = $this->entityManager->getRepository(\App\Entity\EDOPayment::class);

        // Final Payment stats
        $finalPaymentPending = $paymentRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->getQuery()
            ->getSingleScalarResult();

        $finalPaymentApproved = $paymentRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->getQuery()
            ->getSingleScalarResult();

        $finalPaymentRejected = $paymentRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::REJECTED)
            ->getQuery()
            ->getSingleScalarResult();

        // EDO Payment stats
        $edoPaymentPending = $edoPaymentRepo->createQueryBuilder('ep')
            ->select('COUNT(ep.id)')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->getQuery()
            ->getSingleScalarResult();

        $edoPaymentApproved = $edoPaymentRepo->createQueryBuilder('ep')
            ->select('COUNT(ep.id)')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->getQuery()
            ->getSingleScalarResult();

        $edoPaymentRejected = $edoPaymentRepo->createQueryBuilder('ep')
            ->select('COUNT(ep.id)')
            ->where('ep.status = :status')
            ->setParameter('status', PaymentStatus::REJECTED)
            ->getQuery()
            ->getSingleScalarResult();

        // Count amount discrepancies for final payments
        $discrepancyCount = $paymentRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->leftJoin('p.manifest', 'm')
            ->leftJoin('m.billing', 'b')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->andWhere('ABS(p.amount - b.totalAmount) > 0.01')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'final_payment' => [
                'pending' => $finalPaymentPending,
                'approved' => $finalPaymentApproved,
                'rejected' => $finalPaymentRejected,
                'total' => $finalPaymentPending + $finalPaymentApproved + $finalPaymentRejected,
                'discrepancies' => $discrepancyCount,
            ],
            'edo_payment' => [
                'pending' => $edoPaymentPending,
                'approved' => $edoPaymentApproved,
                'rejected' => $edoPaymentRejected,
                'total' => $edoPaymentPending + $edoPaymentApproved + $edoPaymentRejected,
            ],
        ];
    }
}
