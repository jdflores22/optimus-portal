<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/user-hierarchy')]
class UserHierarchyApiController extends BaseApiController
{
    #[Route('/', name: 'api_user_hierarchy_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(['message' => 'User hierarchy API endpoint']);
    }
}