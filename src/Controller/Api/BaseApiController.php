<?php

namespace App\Controller\Api;

use App\Service\JwtService;
use App\Service\UserService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class BaseApiController extends AbstractController
{
    public function __construct(
        protected JwtService $jwtService,
        protected UserService $userService
    ) {}

    protected function authenticateRequest(Request $request): ?User
    {
        // First, try session-based authentication (for web forms)
        $sessionUser = $this->getUser();
        if ($sessionUser instanceof User) {
            return $sessionUser;
        }

        // Fall back to JWT authentication (for API clients)
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        $userId = $this->jwtService->getUserIdFromToken($token);

        if (!$userId) {
            return null;
        }

        return $this->userService->findById($userId);
    }

    protected function requireAuthentication(Request $request): JsonResponse|User
    {
        $user = $this->authenticateRequest($request);
        
        if (!$user) {
            return new JsonResponse([
                'error' => 'Authentication required'
            ], 401);
        }

        return $user;
    }

    protected function requireRole(User $user, array $allowedRoles): ?JsonResponse
    {
        if (!in_array($user->getRole()->value, $allowedRoles)) {
            return new JsonResponse([
                'error' => 'Insufficient permissions'
            ], 403);
        }

        return null;
    }

    protected function jsonResponse(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    protected function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /**
     * Validate required fields in request data
     */
    protected function validateRequiredFields(array $data, array $requiredFields): ?JsonResponse
    {
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            return $this->errorResponse(
                'Missing required fields: ' . implode(', ', $missingFields),
                400
            );
        }

        return null;
    }

    /**
     * Validate file upload
     */
    protected function validateFileUpload($file, array $allowedMimeTypes = [], int $maxSizeBytes = 10485760): ?JsonResponse
    {
        if (!$file) {
            return $this->errorResponse('File is required', 400);
        }

        // Check file size (default 10MB)
        if ($file->getSize() > $maxSizeBytes) {
            $maxSizeMB = $maxSizeBytes / 1048576;
            return $this->errorResponse("File size exceeds maximum allowed size of {$maxSizeMB}MB", 400);
        }

        // Check MIME type if specified
        if (!empty($allowedMimeTypes) && !in_array($file->getMimeType(), $allowedMimeTypes)) {
            return $this->errorResponse(
                'Invalid file type. Allowed types: ' . implode(', ', $allowedMimeTypes),
                400
            );
        }

        return null;
    }

    /**
     * Validate numeric value
     */
    protected function validateNumeric($value, string $fieldName, float $min = null, float $max = null): ?JsonResponse
    {
        if (!is_numeric($value)) {
            return $this->errorResponse("{$fieldName} must be a numeric value", 400);
        }

        $numValue = (float) $value;

        if ($min !== null && $numValue < $min) {
            return $this->errorResponse("{$fieldName} must be at least {$min}", 400);
        }

        if ($max !== null && $numValue > $max) {
            return $this->errorResponse("{$fieldName} must not exceed {$max}", 400);
        }

        return null;
    }

    /**
     * Validate date format
     */
    protected function validateDate($dateString, string $fieldName, string $format = 'Y-m-d H:i:s'): ?JsonResponse
    {
        if (empty($dateString)) {
            return $this->errorResponse("{$fieldName} is required", 400);
        }

        $date = \DateTime::createFromFormat($format, $dateString);
        if (!$date || $date->format($format) !== $dateString) {
            return $this->errorResponse("{$fieldName} must be in format {$format}", 400);
        }

        return null;
    }
}
