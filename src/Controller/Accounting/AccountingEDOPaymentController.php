<?php

namespace App\Controller\Accounting;

use App\Entity\EDOPayment;
use App\Entity\Enum\PaymentStatus;
use App\Service\EDOPaymentServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/accounting/billing-payments')]
#[IsGranted('ROLE_ACCOUNTING')]
class AccountingEDOPaymentController extends AbstractController
{
    public function __construct(
        private EDOPaymentServiceInterface $edoPaymentService,
        private EntityManagerInterface $entityManager,
        private \App\Service\FileStorageServiceInterface $fileStorage,
        private \App\Service\AuditService $auditService
    ) {
    }

    #[Route('/', name: 'accounting_billing_payments_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $statusFilter = $request->query->get('status', 'pending_validation');

        $qb = $this->entityManager->getRepository(EDOPayment::class)
            ->createQueryBuilder('ep')
            ->leftJoin('ep.manifest', 'm')
            ->leftJoin('m.broker', 'b')
            ->leftJoin('m.consignee', 'c')
            ->leftJoin('ep.shippingLine', 'sl')
            ->addSelect('m', 'b', 'c', 'sl')
            ->orderBy('ep.createdAt', 'DESC');

        if ($statusFilter) {
            $qb->andWhere('ep.status = :status')
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

        return $this->render('accounting/billing_payment/index.html.twig', [
            'payments' => $payments,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'statusFilter' => $statusFilter,
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    #[Route('/{id}/validate', name: 'accounting_billing_payment_validate', methods: ['POST'])]
    public function validate(int $id, Request $request): JsonResponse
    {
        try {
            $accountant = $this->getUser();
            
            if (!$accountant) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            $data = json_decode($request->getContent(), true);
            $approved = ($data['approved'] ?? false) === true;
            $reason = $data['reason'] ?? null;

            if (!$approved && empty(trim($reason))) {
                return $this->json([
                    'success' => false,
                    'message' => 'Rejection reason is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->edoPaymentService->validateEDOAccessPayment(
                $id,
                $approved,
                $reason,
                $accountant
            );

            return $this->json([
                'success' => true,
                'message' => $approved
                    ? 'Billing payment approved successfully.'
                    : 'Billing payment rejected successfully.'
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while validating the payment'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/receipt', name: 'accounting_billing_payment_receipt', methods: ['GET'])]
    public function viewReceipt(int $id, Request $request): Response
    {
        try {
            $payment = $this->edoPaymentService->getEDOPaymentById($id);
            
            if (!$payment) {
                throw $this->createNotFoundException('Payment not found');
            }

            $filePath = $payment->getReceiptFilePath();
            if (!$filePath) {
                throw $this->createNotFoundException('Receipt file not found');
            }

            $filePath = ltrim($filePath, '/');
            if (str_starts_with($filePath, 'uploads/')) {
                $filePath = substr($filePath, 8);
            }

            if (!$this->fileStorage->fileExists($filePath)) {
                throw $this->createNotFoundException('Receipt file does not exist');
            }

            $this->auditService->logDocumentDownload($this->getUser(), 'BillingPaymentReceipt', $payment->getId());

            $fullPath = $this->fileStorage->getFullPath($filePath);
            
            if (!file_exists($fullPath)) {
                throw $this->createNotFoundException('Physical file not found');
            }

            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($fullPath);
            
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $contentType = match($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream'
            };

            $response->headers->set('Content-Type', $contentType);
            
            $inline = $request->query->get('inline', 'true') === 'true';
            
            if ($inline) {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
                    'receipt-' . $payment->getId() . '.' . $extension
                );
            } else {
                $response->setContentDisposition(
                    \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    'receipt-' . $payment->getId() . '.' . $extension
                );
            }

            return $response;
        } catch (\Exception $e) {
            throw $this->createNotFoundException('Error loading receipt: ' . $e->getMessage());
        }
    }
}
