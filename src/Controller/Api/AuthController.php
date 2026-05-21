<?php

namespace App\Controller\Api;

use App\Service\JwtService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private UserService $userService,
        private JwtService $jwtService
    ) {}

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse([
                'error' => 'Email and password are required'
            ], 400);
        }

        try {
            $user = $this->userService->authenticateForApi($data['email'], $data['password']);
            
            if (!$user) {
                return new JsonResponse([
                    'error' => 'Invalid credentials'
                ], 401);
            }

            $token = $this->jwtService->generateToken($user);

            return new JsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'role' => $user->getRole()->value
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Authentication failed'
            ], 500);
        }
    }

    #[Route('/validate', name: 'validate', methods: ['POST'])]
    public function validateToken(Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return new JsonResponse([
                'error' => 'Authorization header required'
            ], 401);
        }

        $token = substr($authHeader, 7);
        $payload = $this->jwtService->validateToken($token);

        if (!$payload) {
            return new JsonResponse([
                'error' => 'Invalid token'
            ], 401);
        }

        return new JsonResponse([
            'valid' => true,
            'user' => [
                'id' => $payload['user_id'],
                'email' => $payload['email'],
                'role' => $payload['role']
            ]
        ]);
    }
}