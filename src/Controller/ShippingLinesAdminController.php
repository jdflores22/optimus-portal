<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_SYSTEM_ADMIN')]
class ShippingLinesAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/dashboard', name: 'app_admin_legacy_dashboard_redirect')]
    public function dashboard(): Response
    {
        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/dashboard/users', name: 'app_admin_legacy_users_redirect')]
    public function userManagement(Request $request): Response
    {
        return $this->redirectToRoute('app_admin_users', $request->query->all());
    }

    #[Route('/users/{id}/unlock', name: 'app_admin_unlock_user', methods: ['POST'])]
    public function unlockUser(int $id, Request $request): Response
    {
        // Validate CSRF token
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unlock_user_' . $id, $submittedToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_admin_users');
        }

        $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($id);
        
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_admin_users');
        }

        // Check if user is actually locked
        if ($user->getStatus() !== \App\Entity\Enum\AccountStatus::LOCKED) {
            $this->addFlash('error', 'User account is not locked.');
            return $this->redirectToRoute('app_admin_users');
        }

        try {
            // Unlock the user account
            $user->setStatus(\App\Entity\Enum\AccountStatus::APPROVED);
            $user->setFailedLoginAttempts(0);
            $user->setLockedUntil(null);
            
            $this->entityManager->flush();
            
            $this->addFlash('success', 'User account has been successfully unlocked: ' . $user->getEmail());
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to unlock user account. Please try again.');
        }

        return $this->redirectToRoute('app_admin_users');
    }
}
