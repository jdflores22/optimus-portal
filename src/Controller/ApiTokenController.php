<?php

namespace App\Controller;

use App\Entity\Trucker;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/token')]
class ApiTokenController extends AbstractController
{
    public function __construct(
        private ApiTokenService $apiTokenService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Generate new API token for authenticated trucker
     */
    #[Route('/generate', name: 'api_token_generate', methods: ['POST'])]
    #[IsGranted('ROLE_TRUCKER')]
    public function generateToken(Request $request): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $data = json_decode($request->getContent(), true) ?? [];
            $validityDays = isset($data['validity_days']) ? (int) $data['validity_days'] : 30;
            
            // Validate validity days (between 1 and 365 days)
            if ($validityDays < 1 || $validityDays > 365) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Validity days must be between 1 and 365',
                    'code' => 'INVALID_VALIDITY_DAYS'
                ], 400);
            }

            // Generate new token
            $token = $this->apiTokenService->generateTokenForTrucker($trucker, $validityDays);
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'api_token' => $token,
                    'expires_at' => $trucker->getApiTokenExpiresAt()->format('Y-m-d H:i:s'),
                    'validity_days' => $validityDays,
                    'message' => 'API token generated successfully'
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('API token generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to generate API token',
                'code' => 'TOKEN_GENERATION_FAILED'
            ], 500);
        }
    }

    /**
     * Get current API token status
     */
    #[Route('/status', name: 'api_token_status', methods: ['GET'])]
    #[IsGranted('ROLE_TRUCKER')]
    public function getTokenStatus(): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $statistics = $this->apiTokenService->getTokenStatistics($trucker);
            
            return new JsonResponse([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            $this->logger->error('API token status check failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to get token status',
                'code' => 'TOKEN_STATUS_FAILED'
            ], 500);
        }
    }

    /**
     * Refresh existing API token
     */
    #[Route('/refresh', name: 'api_token_refresh', methods: ['POST'])]
    #[IsGranted('ROLE_TRUCKER')]
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $data = json_decode($request->getContent(), true) ?? [];
            $validityDays = isset($data['validity_days']) ? (int) $data['validity_days'] : 30;
            
            // Validate validity days
            if ($validityDays < 1 || $validityDays > 365) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Validity days must be between 1 and 365',
                    'code' => 'INVALID_VALIDITY_DAYS'
                ], 400);
            }

            // Refresh token
            $token = $this->apiTokenService->refreshTokenForTrucker($trucker, $validityDays);
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'api_token' => $token,
                    'expires_at' => $trucker->getApiTokenExpiresAt()->format('Y-m-d H:i:s'),
                    'validity_days' => $validityDays,
                    'message' => 'API token refreshed successfully'
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('API token refresh failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to refresh API token',
                'code' => 'TOKEN_REFRESH_FAILED'
            ], 500);
        }
    }

    /**
     * Revoke current API token
     */
    #[Route('/revoke', name: 'api_token_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_TRUCKER')]
    public function revokeToken(): JsonResponse
    {
        try {
            /** @var Trucker $trucker */
            $trucker = $this->getUser();
            
            $this->apiTokenService->revokeTokenForTrucker($trucker);
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'message' => 'API token revoked successfully'
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('API token revocation failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to revoke API token',
                'code' => 'TOKEN_REVOCATION_FAILED'
            ], 500);
        }
    }

    /**
     * Validate API token format
     */
    #[Route('/validate', name: 'api_token_validate', methods: ['POST'])]
    #[IsGranted('ROLE_TRUCKER')]
    public function validateToken(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data || !isset($data['token'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Token is required',
                    'code' => 'MISSING_TOKEN'
                ], 400);
            }

            $token = $data['token'];
            $isValidFormat = $this->apiTokenService->validateTokenFormat($token);
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'is_valid_format' => $isValidFormat,
                    'token_length' => strlen($token),
                    'expected_length' => 64
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('API token validation failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to validate token',
                'code' => 'TOKEN_VALIDATION_FAILED'
            ], 500);
        }
    }
}