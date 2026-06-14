<?php

namespace App\Controller;

use App\Entity\Broker;
use App\Entity\ShipmentRecord;
use App\Entity\PaymentVerification;
use App\Entity\User;
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
        $this->addFlash(
            'warning',
            'Legacy shipment payment submission is no longer supported. Please use the manifest payment workflow.'
        );

        return $this->redirectToRoute('broker_dashboard');
    }

    #[Route('/verify/{paymentId}', name: 'payment_verify', methods: ['GET', 'POST'])]
    public function verifyPayment(int $paymentId, Request $request): Response
    {
        $user = $this->getUser();

        return match ($user->getRole()) {
            UserRole::ACCOUNTING => $this->redirectToRoute('accounting_payment_final_list'),
            UserRole::SYSTEM_ADMIN => $this->redirectToRoute('app_admin_dashboard'),
            UserRole::SHIPPING_LINES_ADMIN => $this->redirectToRoute('app_shipping_admin_dashboard'),
            default => $this->redirectToRoute('app_error_access_denied'),
        };
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
    public function listPayments(): Response
    {
        $user = $this->getUser();

        return match ($user->getRole()) {
            UserRole::ACCOUNTING => $this->redirectToRoute('accounting_payment_final_list'),
            UserRole::SYSTEM_ADMIN => $this->redirectToRoute('app_admin_dashboard'),
            UserRole::SHIPPING_LINES_ADMIN => $this->redirectToRoute('app_shipping_admin_dashboard'),
            default => $this->redirectToRoute('app_error_access_denied'),
        };
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
            return $this->redirectToRoute('accounting_payment_final_list');
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
        return $this->redirectToRoute('accounting_payment_final_list');
    }
}