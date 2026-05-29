<?php

namespace App\Controller\Api;

use App\Service\FileStorageServiceInterface;
use App\Service\ManifestAuthorizationServiceInterface;
use App\Service\AuditService;
use App\Service\ActivityLogService;
use App\Service\EDOAccessLogServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Manifest;
use App\Entity\NOADocument;
use App\Entity\Billing;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;

#[Route('/files')]
class FileDownloadController extends AbstractController
{
    public function __construct(
        private FileStorageServiceInterface $fileStorage,
        private ManifestAuthorizationServiceInterface $authorizationService,
        private AuditService $auditService,
        private ActivityLogService $activityLogService,
        private EntityManagerInterface $entityManager,
        private EDOAccessLogServiceInterface $edoAccessLogService
    ) {
    }

    #[Route('/bl/manifest/{manifestId}/download', name: 'api_bl_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadBL(int $manifestId): Response
    {
        $user = $this->getUser();
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($manifestId);

        if (!$manifest) {
            return $this->json(['error' => 'Manifest not found'], Response::HTTP_NOT_FOUND);
        }

        // Authorization check
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $manifest->getBlFilePath();
        $blNumber = $manifest->getBlNumber();

        if (!$filePath || !$blNumber) {
            return $this->json(['error' => 'BL not uploaded yet'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->fileStorage->fileExists($filePath)) {
            return $this->json(['error' => 'BL file not found'], Response::HTTP_NOT_FOUND);
        }

        // Log download
        $this->auditService->logDocumentDownload($user, 'BL', $manifest->getId());
        
        // Log to activity log for notifications
        $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'bl');

        // Serve file
        return $this->serveFile($filePath, 'BL-' . $blNumber . '.pdf');
    }

    #[Route('/download', name: 'api_file_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadFile(): Response
    {
        $user = $this->getUser();
        $path = $_GET['path'] ?? null;

        if (!$path) {
            return $this->json(['error' => 'File path is required'], Response::HTTP_BAD_REQUEST);
        }

        // Security: Ensure the path is within allowed directories
        $normalizedPath = str_replace('\\', '/', $path);
        $allowedPrefixes = ['/uploads/broker/', '/uploads/consignee/', '/uploads/bl/'];
        
        $isAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Check if file exists
        if (!$this->fileStorage->fileExists($path)) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Log download
        $this->auditService->logAction(
            $user,
            'file_download',
            'File',
            null,
            ['file_path' => $path]
        );

        // Serve file
        $filename = basename($path);
        return $this->serveFile($path, $filename);
    }

    #[Route('/manifests/{id}/download', name: 'api_manifest_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadManifest(int $id): Response
    {
        $user = $this->getUser();
        $manifest = $this->entityManager->getRepository(Manifest::class)->find($id);

        if (!$manifest) {
            return $this->json(['error' => 'Manifest not found'], Response::HTTP_NOT_FOUND);
        }

        // Authorization check
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get manifest file path (assuming it's stored in the manifest entity)
        $filePath = $manifest->getManifestFilePath();
        if (!$filePath || !$this->fileStorage->fileExists($filePath)) {
            return $this->json(['error' => 'Manifest file not found'], Response::HTTP_NOT_FOUND);
        }

        // Log download
        $this->auditService->logDocumentDownload($user, 'Manifest', $manifest->getId());
        
        // Log to activity log for notifications
        $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'manifest');

        // Serve file
        return $this->serveFile($filePath, 'manifest-' . $manifest->getManifestNumber() . '.pdf');
    }

    #[Route('/noa/{noaNumber}/download', name: 'api_noa_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadNOA(string $noaNumber): Response
    {
        $user = $this->getUser();
        $noa = $this->entityManager->getRepository(NOADocument::class)
            ->findOneBy(['noaNumber' => $noaNumber]);

        if (!$noa) {
            return $this->json(['error' => 'NOA not found'], Response::HTTP_NOT_FOUND);
        }

        $manifest = $noa->getManifest();

        // Authorization check
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $noa->getPdfPath();
        if (!$filePath || !$this->fileStorage->fileExists($filePath)) {
            return $this->json(['error' => 'NOA file not found'], Response::HTTP_NOT_FOUND);
        }

        // Log download
        $this->auditService->logDocumentDownload($user, 'NOA', $noa->getId());
        
        // Log to activity log for notifications
        $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'noa');

        // Serve file
        return $this->serveFile($filePath, 'NOA-' . $noaNumber . '.pdf');
    }

    #[Route('/billing/{billingId}/download', name: 'api_billing_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadBilling(int $billingId, Request $request): Response
    {
        $user = $this->getUser();
        $billing = $this->entityManager->getRepository(Billing::class)->find($billingId);

        if (!$billing) {
            return $this->json(['error' => 'Billing not found'], Response::HTTP_NOT_FOUND);
        }

        $manifest = $billing->getManifest();

        // Authorization check - only SL_STAFF and authorized Broker can download
        $userRole = $user->getRole()->value;
        $canDownload = $userRole === 'SL_STAFF' || 
                       $userRole === 'SYSTEM_ADMIN' ||
                       $userRole === 'ACCOUNTING' ||
                       ($userRole === 'BROKER' && $this->authorizationService->canViewManifest($manifest, $user));

        if (!$canDownload) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $billing->getPdfPath();
        if (!$filePath || !$this->fileStorage->fileExists($filePath)) {
            return $this->json(['error' => 'Billing file not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if inline viewing is requested
        $inline = $request->query->get('inline', 'true') === 'true';

        // Log download only if not inline viewing
        if (!$inline) {
            $this->auditService->logDocumentDownload($user, 'Billing', $billing->getId());
            $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'billing');
        }

        // Serve file
        return $this->serveFile($filePath, 'billing-' . $billing->getId() . '.pdf', $inline);
    }

    #[Route('/edo/{edoNumber}/download', name: 'api_edo_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadEDO(string $edoNumber, Request $request): Response
    {
        $user = $this->getUser();
        $edo = $this->entityManager->getRepository(ElectronicDeliveryOrder::class)
            ->findOneBy(['edoNumber' => $edoNumber]);

        if (!$edo) {
            return $this->json(['error' => 'eDO not found'], Response::HTTP_NOT_FOUND);
        }

        $manifest = $edo->getManifest();
        $ipAddress = $request->getClientIp() ?? 'unknown';

        // Authorization check
        if (!$this->authorizationService->canViewManifest($manifest, $user)) {
            // Log denied access attempt
            $this->edoAccessLogService->logAccessAttempt($edo, $user, $ipAddress, 'denied');
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Check eDO status
        $edoStatus = $edo->getStatus();
        
        if ($edoStatus === EDOStatus::PENDING_RELEASE) {
            // Log denied access attempt
            $this->edoAccessLogService->logAccessAttempt($edo, $user, $ipAddress, 'denied');
            return $this->json([
                'error' => 'eDO is pending administrative release. Please wait for approval.'
            ], Response::HTTP_FORBIDDEN);
        }

        if ($edoStatus === EDOStatus::REJECTED) {
            // Log denied access attempt
            $this->edoAccessLogService->logAccessAttempt($edo, $user, $ipAddress, 'denied');
            return $this->json([
                'error' => 'eDO has been rejected. Please contact support for assistance.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Only RELEASED status reaches here
        $filePath = $edo->getPdfPath();
        if (!$filePath || !$this->fileStorage->fileExists($filePath)) {
            // Log denied access attempt
            $this->edoAccessLogService->logAccessAttempt($edo, $user, $ipAddress, 'denied');
            return $this->json(['error' => 'eDO file not found'], Response::HTTP_NOT_FOUND);
        }

        // Log successful access
        $this->edoAccessLogService->logAccessAttempt($edo, $user, $ipAddress, 'granted');

        // Log download with user identity, timestamp, and eDO number
        // Requirement 12.4: Log eDO download with user identity, timestamp, and eDO number
        $this->auditService->logAction(
            $user,
            'document_download',
            'ElectronicDeliveryOrder',
            $edo->getId(),
            [
                'edo_number' => $edoNumber,
                'download_time' => date('Y-m-d H:i:s')
            ]
        );
        
        // Log to activity log for notifications
        $this->activityLogService->logManifestDocumentDownload($user, $manifest, 'edo');

        // Serve file
        return $this->serveFile($filePath, 'EDO-' . $edoNumber . '.pdf');
    }

    #[Route('/receipts/{paymentId}/download', name: 'api_receipt_download', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadReceipt(int $paymentId, Request $request): Response
    {
        $user = $this->getUser();
        $payment = $this->entityManager->getRepository(\App\Entity\Payment::class)->find($paymentId);

        if (!$payment) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        // Authorization check - only validators and submitter can download
        $userRole = $user->getRole()->value;
        $isSubmitter = $payment->getSubmittedBy()->getId() === $user->getId();
        $isValidator = in_array($userRole, ['SYSTEM_ADMIN', 'ACCOUNTING', 'SL_STAFF']);

        if (!$isSubmitter && !$isValidator) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $payment->getReceiptFilePath();
        if (!$filePath || !$this->fileStorage->fileExists($filePath)) {
            return $this->json(['error' => 'Receipt file not found'], Response::HTTP_NOT_FOUND);
        }

        // Log download
        $this->auditService->logDocumentDownload($user, 'PaymentReceipt', $payment->getId());

        // Check if inline viewing is requested (for iframe)
        $inline = $request->query->get('inline', 'false') === 'true';

        // Serve file
        $filename = 'receipt-' . $payment->getId() . '.' . pathinfo($filePath, PATHINFO_EXTENSION);
        return $this->serveFile($filePath, $filename, $inline);
    }

    private function serveFile(string $relativePath, string $downloadFilename, bool $inline = false): BinaryFileResponse
    {
        $fullPath = $this->fileStorage->getFullPath($relativePath);

        $response = new BinaryFileResponse($fullPath);
        
        // Set proper Content-Type based on file extension
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentType = match($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream'
        };

        $response->headers->set('Content-Type', $contentType);
        
        // Set Content-Disposition based on inline parameter
        if ($inline) {
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $downloadFilename
            );
            // Allow iframe embedding from same origin
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        } else {
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $downloadFilename
            );
        }

        return $response;
    }
}
