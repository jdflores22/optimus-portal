<?php

namespace App\Controller\Admin;

use App\Service\PaymentFeeConfigurationServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/payment-fee-config')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class PaymentFeeConfigurationController extends AbstractController
{
    public function __construct(
        private PaymentFeeConfigurationServiceInterface $paymentFeeConfigService,
        private \Doctrine\ORM\EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Display payment fee configuration view
     * 
     * GET /admin/payment-fee-config
     * Requirements: 16.1, 16.4, 16.7, 11.1
     */
    #[Route('', name: 'admin_payment_fee_config', methods: ['GET'])]
    public function index(): Response
    {
        // Clear entity manager to get fresh data
        $this->entityManager->clear();
        
        $currentFee = $this->paymentFeeConfigService->getCurrentManifestAccessFee();
        $currentQrCode = $this->paymentFeeConfigService->getCurrentQrCodePath();
        $history = $this->paymentFeeConfigService->getFeeConfigurationHistory();

        return $this->render('admin/payment_fee_config/index.html.twig', [
            'currentFee' => $currentFee,
            'currentQrCode' => $currentQrCode,
            'history' => $history,
        ]);
    }

    /**
     * Update payment fee configuration
     * 
     * POST /admin/payment-fee-config/update
     * Requirements: 16.2, 16.3, 16.6
     */
    #[Route('/update', name: 'admin_payment_fee_config_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        try {
            $admin = $this->getUser();
            
            if (!$admin) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Get new amount from request
            $data = json_decode($request->getContent(), true);
            $newAmount = $data['amount'] ?? null;

            if ($newAmount === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Amount is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Convert to float and validate
            $newAmount = (float) $newAmount;

            if ($newAmount <= 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'Amount must be a positive decimal value'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update the fee
            $this->paymentFeeConfigService->updateManifestAccessFee($newAmount, $admin);

            return $this->json([
                'success' => true,
                'message' => 'Payment fee updated successfully',
                'data' => [
                    'amount' => $newAmount,
                    'configuredBy' => $admin->getEmail(),
                    'configuredAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while updating the payment fee'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload QR code for payment
     * 
     * POST /admin/payment-fee-config/upload-qr
     */
    #[Route('/upload-qr', name: 'admin_payment_fee_config_upload_qr', methods: ['POST'])]
    public function uploadQrCode(Request $request): JsonResponse
    {
        try {
            $admin = $this->getUser();
            
            if (!$admin) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            $file = $request->files->get('qr_code');
            
            if (!$file) {
                return $this->json([
                    'success' => false,
                    'message' => 'No file uploaded'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file type
            $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid file type. Only JPG and PNG images are allowed.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file size (max 2MB)
            if ($file->getSize() > 2097152) {
                return $this->json([
                    'success' => false,
                    'message' => 'File size must not exceed 2MB'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate unique filename
            $filename = 'qr_code_' . uniqid() . '.' . $file->guessExtension();
            
            // Move file to uploads directory
            $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/qr_codes';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            
            $file->move($uploadsDir, $filename);
            
            // Save path to database
            $qrCodePath = '/uploads/qr_codes/' . $filename;
            $this->paymentFeeConfigService->updateQrCode($qrCodePath, $admin);

            return $this->json([
                'success' => true,
                'message' => 'QR code uploaded successfully',
                'data' => [
                    'qrCodePath' => $qrCodePath
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'An error occurred while uploading QR code'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
