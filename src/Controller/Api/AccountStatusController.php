<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/account')]
class AccountStatusController extends AbstractController
{
    #[Route('/status', name: 'api_account_status', methods: ['GET'])]
    public function checkStatus(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user) {
            return new JsonResponse([
                'authenticated' => false
            ], 401);
        }

        return new JsonResponse([
            'authenticated' => true,
            'status' => $user->getStatus()->value,
            'role' => $user->getRole()->value,
            'email' => $user->getEmail(),
        ]);
    }
}
