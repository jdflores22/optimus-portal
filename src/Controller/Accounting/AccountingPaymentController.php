<?php

namespace App\Controller\Accounting;

use App\Service\BillingServiceInterface;
use App\Service\DocumentTemplateDeveloperSettingsService;
use App\Service\FileStorageServiceInterface;
use App\Service\OfficialReceiptDocumentGenerator;
use App\Service\PaymentService;
use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/accounting/payments')]
#[IsGranted('ROLE_ACCOUNTING')]
class AccountingPaymentController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private EntityManagerInterface $entityManager,
        private BillingServiceInterface $billingService,
        private FileStorageServiceInterface $fileStorage,
        private OfficialReceiptDocumentGenerator $officialReceiptDocumentGenerator,
        private DocumentTemplateDeveloperSettingsService $documentTemplateDeveloperSettings,
    ) {
    }

    #[Route('/dashboard', name: 'accounting_payment_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        return $this->redirectToRoute('accounting_payment_final_list', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/final', name: 'accounting_payment_final_list', methods: ['GET'])]
    public function finalPaymentList(Request $request): Response
    {
        $allowedLimits = [10, 20, 50];
        $limit = (int) $request->query->get('limit', 20);
        if (!in_array($limit, $allowedLimits, true)) {
            $limit = 20;
        }

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
        if ($statusFilter && $statusFilter !== 'all') {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $statusFilter);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $limit));
        $page = max(1, min((int) $request->query->get('page', 1), $totalPages));

        $payments = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $startItem = $total > 0 ? (($page - 1) * $limit) + 1 : 0;
        $endItem = min($page * $limit, $total);

        return $this->render('accounting/payment/final_payment_list.html.twig', [
            'payments' => $payments,
            'statusFilter' => $statusFilter,
            'paymentStatuses' => PaymentStatus::cases(),
            'stats' => $this->getPaymentStatistics(),
            'allowedLimits' => $allowedLimits,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $totalPages,
                'start' => $startItem,
                'end' => $endItem,
            ],
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

        return $this->render('accounting/payment/final_payment_detail.html.twig', [
            'payment' => $payment,
            'billing' => $billing,
            'discrepancy' => $discrepancy,
            'validationHistory' => $validationHistory,
            'billingPdfRegenerateEnabled' => $this->documentTemplateDeveloperSettings->isBillingPdfRegenerateEnabled(),
            'officialReceiptPdfRegenerateEnabled' => $this->documentTemplateDeveloperSettings->isOfficialReceiptPdfRegenerateEnabled(),
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

    #[Route('/final/{id}/billing/download', name: 'accounting_payment_billing_download', methods: ['GET'])]
    public function downloadBilling(int $id, Request $request): Response
    {
        $payment = $this->entityManager->getRepository(\App\Entity\Payment::class)->find($id);

        if (!$payment || $payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw $this->createNotFoundException('Payment not found');
        }

        $billing = $payment->getManifest()->getBilling();
        if (!$billing) {
            throw $this->createNotFoundException('Billing not found for this manifest');
        }

        $fullPath = $billing->getPdfPath()
            ? $this->resolveStoredPdfPath($billing->getPdfPath())
            : null;

        if (!$fullPath) {
            try {
                $billing = $this->billingService->regenerateBillingPdf((int) $billing->getId());
                $pdfPath = $billing->getPdfPath();
                $fullPath = $pdfPath ? $this->resolveStoredPdfPath($pdfPath) : null;
            } catch (\Throwable $e) {
                throw $this->createNotFoundException('Failed to generate billing PDF: ' . $e->getMessage());
            }
        }

        if (!$fullPath) {
            throw $this->createNotFoundException('Billing PDF file not found on server');
        }

        $inline = $request->query->get('inline', 'false') === 'true';
        $filename = sprintf('Billing-%s.pdf', str_pad((string) $billing->getId(), 5, '0', STR_PAD_LEFT));
        $disposition = $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        $response = $this->file($fullPath, $filename, $disposition);
        $response->headers->set('Content-Type', 'application/pdf');

        if ($inline) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        return $response;
    }

    #[Route('/final/{id}/billing/regenerate', name: 'accounting_payment_billing_regenerate', methods: ['POST'])]
    public function regenerateBilling(int $id, Request $request): Response
    {
        if (!$this->documentTemplateDeveloperSettings->isBillingPdfRegenerateEnabled()) {
            $this->addFlash('error', 'Billing PDF regeneration is disabled in document template developer settings.');
            return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
        }

        if (!$this->isCsrfTokenValid('regenerate_billing_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $payment = $this->findFinalPayment($id);
        $billing = $payment->getManifest()->getBilling();
        if (!$billing) {
            $this->addFlash('error', 'Billing not found for this payment.');
            return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
        }

        try {
            $markAsPaid = $payment->getStatus() === PaymentStatus::VERIFIED;
            $this->billingService->regenerateBillingPdf((int) $billing->getId(), $markAsPaid);
            $this->addFlash(
                'success',
                $markAsPaid
                    ? 'Billing statement regenerated with PAID status.'
                    : 'Billing statement regenerated.'
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Failed to regenerate billing statement: ' . $e->getMessage());
        }

        return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
    }

    #[Route('/final/{id}/official-receipt/regenerate', name: 'accounting_payment_official_receipt_regenerate', methods: ['POST'])]
    public function regenerateOfficialReceipt(int $id, Request $request): Response
    {
        if (!$this->documentTemplateDeveloperSettings->isOfficialReceiptPdfRegenerateEnabled()) {
            $this->addFlash('error', 'Official receipt regeneration is disabled in document template developer settings.');
            return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
        }

        if (!$this->isCsrfTokenValid('regenerate_official_receipt_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $payment = $this->findFinalPayment($id);
        if ($payment->getStatus() !== PaymentStatus::VERIFIED) {
            $this->addFlash('error', 'Official receipt can only be generated for approved payments.');
            return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
        }

        try {
            $officialReceiptPath = $this->officialReceiptDocumentGenerator->generateOfficialReceipt($payment);
            $payment->setOfficialReceiptPath($officialReceiptPath);
            $this->entityManager->flush();
            $this->addFlash('success', 'Official receipt regenerated successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Failed to regenerate official receipt: ' . $e->getMessage());
        }

        return $this->redirectToRoute('accounting_payment_final_detail', ['id' => $id]);
    }

    private function findFinalPayment(int $id): \App\Entity\Payment
    {
        $payment = $this->entityManager->getRepository(\App\Entity\Payment::class)->find($id);

        if (!$payment || $payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw $this->createNotFoundException('Payment not found');
        }

        return $payment;
    }

    private function resolveStoredPdfPath(string $relativePath): ?string
    {
        if ($this->fileStorage->fileExists($relativePath)) {
            return $this->fileStorage->getFullPath($relativePath);
        }

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        foreach ([
            $projectDir . '/public/uploads/' . $relativePath,
            $projectDir . '/var/share/' . $relativePath,
            $relativePath,
        ] as $candidatePath) {
            if (is_file($candidatePath)) {
                return $candidatePath;
            }
        }

        return null;
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
