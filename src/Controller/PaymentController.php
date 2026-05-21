<?php

namespace App\Controller;

use App\Entity\Broker;
use App\Entity\ShipmentRecord;
use App\Entity\PaymentVerification;
use App\Entity\User;
use App\Entity\Enum\PaymentStatus;
use App\Entity\Enum\UserRole;
use App\Service\FileService;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/payment')]
#[IsGranted('ROLE_USER')]
class PaymentController extends AbstractController
{
    public function __construct(
        private PaymentService $paymentService,
        private FileService $fileService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/submit/{shipmentId}', name: 'payment_submit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_BROKER')]
    public function submitPaymentProof(int $shipmentId, Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof Broker) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        // Get the shipment record
        $shipment = $this->entityManager->getRepository(ShipmentRecord::class)->find($shipmentId);
        
        if (!$shipment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if broker has access to this shipment
        if (!$shipment->getAuthorizedBrokers()->contains($user)) {
            throw $this->createAccessDeniedException('You do not have access to this shipment');
        }

        // Check if payment proof already exists
        $existingPayment = $this->entityManager->getRepository(PaymentVerification::class)
            ->findOneBy(['shipment' => $shipment, 'broker' => $user]);

        if ($request->isMethod('POST')) {
            $uploadedFile = $request->files->get('payment_proof');

            if (!$uploadedFile || !$uploadedFile->isValid()) {
                $this->addFlash('error', 'Please upload a valid payment proof file');
                return $this->redirectToRoute('payment_submit', ['shipmentId' => $shipmentId]);
            }

            try {
                // Upload the payment proof file
                $storedFile = $this->fileService->uploadFile($uploadedFile, 'payment_proof', $user);

                // Submit payment proof
                $paymentVerification = $this->paymentService->submitPaymentProof($shipmentId, $user, $uploadedFile);

                $this->addFlash('success', 'Payment proof submitted successfully!');
                return $this->redirectToRoute('broker_shipment_detail', ['id' => $shipmentId]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to submit payment proof: ' . $e->getMessage());
            }
        }

        return $this->render('payment/submit.html.twig', [
            'shipment' => $shipment,
            'existingPayment' => $existingPayment
        ]);
    }

    #[Route('/verify/{paymentId}', name: 'payment_verify', methods: ['GET', 'POST'])]
    public function verifyPayment(int $paymentId, Request $request): Response
    {
        $user = $this->getUser();
        
        // Check if user has permission to access payment verification
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        // Get the payment verification record
        $payment = $this->entityManager->getRepository(PaymentVerification::class)->find($paymentId);
        
        if (!$payment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        if ($request->isMethod('POST')) {
            // Only ACCOUNTING role can actually verify/reject payments
            if ($user->getRole()->value !== 'ACCOUNTING') {
                return $this->redirectToRoute('app_error_access_denied');
            }
            
            $action = $request->request->get('action');

            try {
                if ($action === 'verify') {
                    $this->paymentService->verifyPayment($paymentId, $user);
                    $this->addFlash('success', 'Payment verified successfully! EDO has been generated and sent via email to the broker and consignee.');
                } elseif ($action === 'reject') {
                    $this->paymentService->rejectPayment($paymentId, $user);
                    $this->addFlash('success', 'Payment rejected.');
                }

                return $this->redirectToRoute('accounting_dashboard');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to process payment: ' . $e->getMessage());
            }
        }

        return $this->render('payment/verify.html.twig', [
            'payment' => $payment
        ]);
    }

    #[Route('/file/{fileId}/view', name: 'payment_file_view', methods: ['GET'], requirements: ['fileId' => '.+'])]
    public function viewPaymentFile(string $fileId): Response
    {
        $user = $this->getUser();

        try {
            // First try to get file using FileService (for new file IDs)
            $fileResponse = $this->fileService->getFileResponse($fileId, $user);
            
            if ($fileResponse) {
                $response = new Response($fileResponse['content']);
                $response->headers->set('Content-Type', $fileResponse['mimeType']);
                $response->headers->set('Content-Disposition', 'inline; filename="' . $fileResponse['filename'] . '"');
                $response->headers->set('Content-Length', (string) $fileResponse['size']);
                
                // Add cache headers for better performance
                $response->headers->set('Cache-Control', 'public, max-age=3600');
                $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
                
                // Allow iframe embedding by removing restrictive CSP headers
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
                $response->headers->remove('X-Content-Type-Options');
                
                return $response;
            }
        } catch (\Exception $e) {
            // If FileService fails, try legacy file path approach
        }

        // Fallback for legacy file paths (payment_proofs/filename.pdf)
        try {
            return $this->viewLegacyPaymentFile($fileId, $user);
        } catch (\Exception $e) {
            throw $this->createNotFoundException('File not found: ' . $e->getMessage());
        }
    }

    #[Route('/file/{fileId}', name: 'payment_file_download', methods: ['GET'], requirements: ['fileId' => '.+'])]
    public function downloadPaymentFile(string $fileId): Response
    {
        $user = $this->getUser();

        try {
            // First try to get file using FileService (for new file IDs)
            $fileResponse = $this->fileService->getFileResponse($fileId, $user);
            
            if ($fileResponse) {
                $response = new Response($fileResponse['content']);
                $response->headers->set('Content-Type', $fileResponse['mimeType']);
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileResponse['filename'] . '"');
                $response->headers->set('Content-Length', (string) $fileResponse['size']);
                return $response;
            }
        } catch (\Exception $e) {
            // If FileService fails, try legacy file path approach
        }

        // Fallback for legacy file paths (payment_proofs/filename.pdf)
        try {
            return $this->downloadLegacyPaymentFile($fileId, $user);
        } catch (\Exception $e) {
            throw $this->createNotFoundException('File not found: ' . $e->getMessage());
        }
    }

    /**
     * Handle legacy payment file downloads for files stored with full paths
     */
    private function downloadLegacyPaymentFile(string $filePath, User $user): Response
    {
        // URL decode the file path to handle special characters
        $decodedFilePath = urldecode($filePath);
        
        // Try both the original and decoded paths
        $payment = $this->entityManager->getRepository(PaymentVerification::class)
            ->findOneBy(['proofFilePath' => $filePath]);
            
        if (!$payment) {
            $payment = $this->entityManager->getRepository(PaymentVerification::class)
                ->findOneBy(['proofFilePath' => $decodedFilePath]);
        }
            
        if (!$payment) {
            throw $this->createNotFoundException('Payment file not found');
        }
        
        // Check if user has permission to access this file
        if (!$this->canAccessPaymentFile($payment, $user)) {
            throw $this->createAccessDeniedException('Access denied to this file');
        }
        
        // Use the actual file path from the database
        $actualFilePath = $payment->getProofFilePath();
        
        // Build full file path
        $fullPath = $this->getParameter('kernel.project_dir') . '/var/uploads/' . $actualFilePath;
        
        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('File not found on disk: ' . $fullPath);
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw $this->createNotFoundException('Failed to read file');
        }
        
        $filename = basename($actualFilePath);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        
        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) strlen($content));
        
        return $response;
    }

    /**
     * Handle legacy payment file viewing (inline) for files stored with full paths
     */
    private function viewLegacyPaymentFile(string $filePath, User $user): Response
    {
        // URL decode the file path to handle special characters
        $decodedFilePath = urldecode($filePath);
        
        // Try both the original and decoded paths
        $payment = $this->entityManager->getRepository(PaymentVerification::class)
            ->findOneBy(['proofFilePath' => $filePath]);
            
        if (!$payment) {
            $payment = $this->entityManager->getRepository(PaymentVerification::class)
                ->findOneBy(['proofFilePath' => $decodedFilePath]);
        }
            
        if (!$payment) {
            throw $this->createNotFoundException('Payment file not found');
        }
        
        // Check if user has permission to access this file
        if (!$this->canAccessPaymentFile($payment, $user)) {
            throw $this->createAccessDeniedException('Access denied to this file');
        }
        
        // Use the actual file path from the database
        $actualFilePath = $payment->getProofFilePath();
        
        // Build full file path
        $fullPath = $this->getParameter('kernel.project_dir') . '/var/uploads/' . $actualFilePath;
        
        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('File not found on disk: ' . $fullPath);
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw $this->createNotFoundException('Failed to read file');
        }
        
        $filename = basename($actualFilePath);
        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        
        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) strlen($content));
        
        // Add cache headers for better performance
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
        
        // Allow iframe embedding by setting appropriate headers
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->remove('X-Content-Type-Options');
        
        return $response;
    }
    
    /**
     * Check if user can access a payment file
     */
    private function canAccessPaymentFile(PaymentVerification $payment, User $user): bool
    {
        // System Admin can access all payment files
        if ($user->getRole()->value === 'SYSTEM_ADMIN') {
            return true;
        }
        
        // Shipping Lines Admin can access all payment files
        if ($user->getRole()->value === 'SHIPPING_LINES_ADMIN') {
            return true;
        }
        
        // Accounting staff can access all payment files
        if ($user->getRole()->value === 'ACCOUNTING') {
            return true;
        }
        
        // Brokers can only access their own payment files
        if ($user->getRole()->value === 'BROKER' && $payment->getBroker() === $user) {
            return true;
        }
        
        // SL Staff can access all payment files
        if ($user->getRole()->value === 'SL_STAFF') {
            return true;
        }
        
        return false;
    }

    #[Route('/list', name: 'payment_list', methods: ['GET'])]
    public function listPayments(Request $request): Response
    {
        $user = $this->getUser();
        
        // Check if user has permission to access payment list
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        // Get separate lists for each status
        $pendingPayments = $this->entityManager->getRepository(PaymentVerification::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.shipment', 's')
            ->leftJoin('p.broker', 'b')
            ->where('p.status = :status')
            ->setParameter('status', PaymentStatus::PENDING_VALIDATION)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $verifiedPayments = $this->entityManager->getRepository(PaymentVerification::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.shipment', 's')
            ->leftJoin('p.broker', 'b')
            ->where('p.status = :status')
            ->setParameter('status', PaymentStatus::VERIFIED)
            ->orderBy('p.verifiedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $rejectedPayments = $this->entityManager->getRepository(PaymentVerification::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.shipment', 's')
            ->leftJoin('p.broker', 'b')
            ->where('p.status = :status')
            ->setParameter('status', PaymentStatus::REJECTED)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Get counts for each status
        $statusCounts = [
            'PENDING' => count($pendingPayments),
            'VERIFIED' => count($verifiedPayments),
            'REJECTED' => count($rejectedPayments)
        ];

        return $this->render('payment/list.html.twig', [
            'pendingPayments' => $pendingPayments,
            'verifiedPayments' => $verifiedPayments,
            'rejectedPayments' => $rejectedPayments,
            'statusCounts' => $statusCounts
        ]);
    }

    /**
     * Download EDO PDF for accounting staff and system administrators
     */
    #[Route('/edo/{paymentId}/download', name: 'payment_edo_download', methods: ['GET'])]
    public function downloadEdo(int $paymentId): Response
    {
        $user = $this->getUser();
        
        // Check if user has permission to access EDO files
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        // Get the payment verification record
        $payment = $this->entityManager->getRepository(PaymentVerification::class)->find($paymentId);
        
        if (!$payment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if payment is verified and has EDO
        if ($payment->getStatus() !== PaymentStatus::VERIFIED || !$payment->getEdo()) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        $edo = $payment->getEdo();

        try {
            // Decrypt and serve the EDO file
            $decryptedContent = $this->fileService->decryptFile($edo->getPdfPath());
            
            $response = new Response($decryptedContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'attachment; filename="EDO_' . $edo->getEdoNumber() . '.pdf"');
            $response->headers->set('Content-Length', strlen($decryptedContent));
            
            return $response;
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unable to download EDO file. Please contact support.');
            return $this->redirectToRoute('payment_verify', ['paymentId' => $paymentId]);
        }
    }

    /**
     * View EDO PDF for accounting staff and system administrators (inline)
     */
    #[Route('/edo/{paymentId}/view', name: 'payment_edo_view', methods: ['GET'])]
    public function viewEdo(int $paymentId): Response
    {
        $user = $this->getUser();
        
        // Check if user has permission to access EDO files
        if (!in_array($user->getRole()->value, ['ACCOUNTING', 'SYSTEM_ADMIN', 'SHIPPING_LINES_ADMIN'])) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        // Get the payment verification record
        $payment = $this->entityManager->getRepository(PaymentVerification::class)->find($paymentId);
        
        if (!$payment) {
            return $this->redirectToRoute('app_error_general', ['code' => 404]);
        }

        // Check if payment is verified and has EDO
        if ($payment->getStatus() !== PaymentStatus::VERIFIED || !$payment->getEdo()) {
            return $this->redirectToRoute('app_error_access_denied');
        }

        $edo = $payment->getEdo();

        try {
            // Decrypt and serve the EDO file for inline viewing
            $decryptedContent = $this->fileService->decryptFile($edo->getPdfPath());
            
            $response = new Response($decryptedContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'inline; filename="EDO_' . $edo->getEdoNumber() . '.pdf"');
            $response->headers->set('Content-Length', strlen($decryptedContent));
            
            // Add cache headers for better performance
            $response->headers->set('Cache-Control', 'public, max-age=3600');
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
            
            // Allow iframe embedding
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->remove('X-Content-Type-Options');
            
            return $response;
        } catch (\Exception $e) {
            throw $this->createNotFoundException('EDO file not found: ' . $e->getMessage());
        }
    }

    #[Route('/dashboard', name: 'accounting_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_ACCOUNTING')]
    public function accountingDashboard(): Response
    {
        // Get payment statistics
        $pendingCount = $this->entityManager->getRepository(PaymentVerification::class)
            ->count(['status' => PaymentStatus::PENDING_VALIDATION]);
        
        $verifiedCount = $this->entityManager->getRepository(PaymentVerification::class)
            ->count(['status' => PaymentStatus::VERIFIED]);
        
        $rejectedCount = $this->entityManager->getRepository(PaymentVerification::class)
            ->count(['status' => PaymentStatus::REJECTED]);

        // Get recent pending payments
        $recentPendingPayments = $this->entityManager->getRepository(PaymentVerification::class)
            ->findBy(['status' => PaymentStatus::PENDING_VALIDATION], ['createdAt' => 'DESC'], 5);

        // Get recent verified payments
        $recentVerifiedPayments = $this->entityManager->getRepository(PaymentVerification::class)
            ->findBy(['status' => PaymentStatus::VERIFIED], ['verifiedAt' => 'DESC'], 5);

        return $this->render('payment/dashboard.html.twig', [
            'pendingCount' => $pendingCount,
            'verifiedCount' => $verifiedCount,
            'rejectedCount' => $rejectedCount,
            'recentPendingPayments' => $recentPendingPayments,
            'recentVerifiedPayments' => $recentVerifiedPayments
        ]);
    }
}