<?php

namespace App\Controller\Broker;

use App\Entity\Payment;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\PaymentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/broker/payments')]
#[IsGranted('ROLE_BROKER')]
class BrokerPaymentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/rejected', name: 'broker_payments_rejected', methods: ['GET'])]
    public function rejectedPayments(Request $request): Response
    {
        $user = $this->getUser();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        // Get rejected payments for this broker
        $qb = $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.manifest', 'm')
            ->addSelect('m')
            ->leftJoin('m.billing', 'b')
            ->addSelect('b')
            ->leftJoin('p.validatedBy', 'v')
            ->addSelect('v')
            ->where('p.paymentType = :type')
            ->andWhere('p.status = :status')
            ->andWhere('p.submittedBy = :broker')
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('status', PaymentStatus::REJECTED)
            ->setParameter('broker', $user)
            ->orderBy('p.validatedAt', 'DESC');

        // Pagination
        $totalQuery = clone $qb;
        $total = count($totalQuery->getQuery()->getResult());
        $totalPages = ceil($total / $limit);

        $payments = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('broker/payment/rejected_payments.html.twig', [
            'payments' => $payments,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    #[Route('/rejected/{id}', name: 'broker_payments_rejected_detail', methods: ['GET'])]
    public function rejectedPaymentDetail(int $id): Response
    {
        $user = $this->getUser();
        
        $payment = $this->entityManager->getRepository(Payment::class)->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        // Verify this payment belongs to the current broker
        if ($payment->getSubmittedBy()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You do not have access to this payment');
        }

        if ($payment->getStatus() !== PaymentStatus::REJECTED) {
            throw $this->createNotFoundException('Payment is not rejected');
        }

        if ($payment->getPaymentType() !== PaymentType::FINAL_PAYMENT) {
            throw $this->createNotFoundException('Invalid payment type');
        }

        // Redirect to the final payment page for resubmission
        return $this->redirectToRoute('broker_manifest_final_payment', [
            'id' => $payment->getManifest()->getId()
        ]);
    }

    #[Route('/history/{id}', name: 'broker_payment_history', methods: ['GET'])]
    public function paymentHistory(int $id): Response
    {
        $user = $this->getUser();
        
        // Get manifest
        $manifest = $this->entityManager->getRepository(\App\Entity\Manifest::class)->find($id);

        if (!$manifest) {
            throw $this->createNotFoundException('Manifest not found');
        }

        // Verify this manifest belongs to the current broker
        if ($manifest->getBroker()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You do not have access to this manifest');
        }

        // Get all payment versions for this manifest (final payments only)
        $payments = $this->entityManager->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.submittedBy', 'sb')
            ->addSelect('sb')
            ->leftJoin('p.validatedBy', 'vb')
            ->addSelect('vb')
            ->where('p.manifest = :manifest')
            ->andWhere('p.paymentType = :type')
            ->andWhere('p.submittedBy = :broker')
            ->setParameter('manifest', $manifest)
            ->setParameter('type', PaymentType::FINAL_PAYMENT)
            ->setParameter('broker', $user)
            ->orderBy('p.version', 'ASC')
            ->getQuery()
            ->getResult();

        // Calculate statistics
        $totalVersions = count($payments);
        $totalRejections = count(array_filter($payments, fn($p) => $p->getStatus() === PaymentStatus::REJECTED));
        $currentVersion = $totalVersions > 0 ? end($payments)->getVersion() : 0;
        $firstSubmission = $totalVersions > 0 ? $payments[0]->getCreatedAt() : null;
        $lastSubmission = $totalVersions > 0 ? end($payments)->getCreatedAt() : null;

        $statistics = [
            'total_versions' => $totalVersions,
            'total_rejections' => $totalRejections,
            'current_version' => $currentVersion,
            'first_submission' => $firstSubmission,
            'last_submission' => $lastSubmission,
        ];

        return $this->render('broker/payment/payment_history.html.twig', [
            'manifest' => $manifest,
            'payments' => $payments,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/receipt/{id}', name: 'broker_payment_receipt', methods: ['GET'])]
    public function viewReceipt(int $id, Request $request): Response
    {
        $user = $this->getUser();
        
        $payment = $this->entityManager->getRepository(Payment::class)->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Payment not found');
        }

        // Verify this payment belongs to the current broker
        if ($payment->getSubmittedBy()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You do not have access to this receipt');
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

        // Build full path
        $projectDir = $this->getParameter('kernel.project_dir');
        $fullPath = $projectDir . '/public' . $receiptPath;

        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Receipt file not found');
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
}
