<?php

namespace App\Controller\Accounting;

use App\Service\PaymentService;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/accounting/payments')]
#[IsGranted('ROLE_ACCOUNTING')]
class AccountingPaymentController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/dashboard', name: 'accounting_payment_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        // Get filter parameters
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $status = $request->query->get('status');
        $submitter = $request->query->get('submitter');

        // Get statistics
        $stats = $this->getPaymentStatistics();

        // Get recent validation activity
        $qb = $this->entityManager->getRepository(\App\Entity\Payment::class)
            ->createQueryBuilder('p')
            ->where('p.paymentType = :type')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(10);

        // Apply filters
        if ($dateFrom) {
            $qb->andWhere('p.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('p.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        if ($status) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $status);
        }

        if ($submitter) {
            $qb->leftJoin('p.submittedBy', 'u')
               ->andWhere('u.fullName LIKE :submitter OR u.email LIKE :submitter')
               ->setParameter('submitter', '%' . $submitter . '%');
        }

        $recentActivity = $qb->getQuery()->getResult();

        return $this->render('accounting/payment/dashboard.html.twig', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'status' => $status,
                'submitter' => $submitter,
            ],
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    #[Route('/final', name: 'accounting_payment_final_list', methods: ['GET'])]
    public function finalPaymentList(Request $request): Response
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

        return $this->render('accounting/payment/final_payment_list.html.twig', [
            'payments' => $payments,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'statusFilter' => $statusFilter,
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    #[Route('/final/{id}', name: 'accounting_payment_final_detail', methods: ['GET'])]
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

        // Calculate amount discrepancy
        $discrepancy = abs($payment->getAmount() - $billing->getTotalAmount());

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

        return $this->render('accounting/payment/final_payment_detail.html.twig', [
            'payment' => $payment,
            'billing' => $billing,
            'discrepancy' => $discrepancy,
            'validationHistory' => $validationHistory,
        ]);
    }

    #[Route('/final/{id}/receipt', name: 'accounting_payment_receipt', methods: ['GET'])]
    public function viewPaymentReceipt(int $id, Request $request): Response
    {
        $payment = $this->entityManager->getRepository(\App\Entity\Payment::class)->find($id);
        
        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        // Check if requesting official receipt or broker receipt
        $type = $request->query->get('type', 'broker');
        
        if ($type === 'official') {
            $receiptPath = $payment->getOfficialReceiptPath();
            if (!$receiptPath) {
                throw $this->createNotFoundException('Official receipt not found');
            }
        } else {
            $receiptPath = $payment->getReceiptFilePath();
            if (!$receiptPath) {
                throw $this->createNotFoundException('Receipt not found');
            }
        }

        // Build full path - receipts are stored in public/uploads directory
        // Database path already includes /uploads/ prefix, so we just need to add public/
        $projectDir = $this->getParameter('kernel.project_dir');
        $fullPath = $projectDir . '/public' . $receiptPath;

        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Receipt file not found at path: ' . $receiptPath);
        }

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($fullPath);
        
        // Detect file type
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'application/octet-stream'
        };

        $response->headers->set('Content-Type', $contentType);
        
        // Check if inline viewing is requested
        $inline = $request->query->get('inline', 'true') === 'true';
        
        $filename = $type === 'official' 
            ? 'official-receipt-' . $payment->getId() . '.' . $extension
            : 'receipt-' . $payment->getId() . '.' . $extension;
        
        if ($inline) {
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                $filename
            );
        } else {
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            );
        }

        return $response;
    }

    private function getPaymentStatistics(): array
    {
        $repo = $this->entityManager->getRepository(\App\Entity\Payment::class);

        // Final Payment stats
        $pending = $repo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->getQuery()
            ->getSingleScalarResult();

        $approved = $repo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->getQuery()
            ->getSingleScalarResult();

        $rejected = $repo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::REJECTED)
            ->getQuery()
            ->getSingleScalarResult();

        // Count amount discrepancies for final payments
        $discrepancies = $repo->createQueryBuilder('p')
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
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
            'discrepancies' => $discrepancies,
        ];
    }
}
