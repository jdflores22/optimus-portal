<?php

namespace App\Controller;

use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Doctrine\ORM\EntityManagerInterface;

class LoginController extends AbstractController
{
    public function __construct(
        private UserService $userService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If user is already logged in, redirect to home
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        // Check if account is locked
        $lockMessage = null;
        if ($lastUsername) {
            $user = $this->entityManager->getRepository(\App\Entity\User::class)
                ->findOneBy(['email' => $lastUsername]);
            
            if ($user && $user->isLocked()) {
                $lockMessage = 'Your account has been locked due to multiple failed login attempts. Please try again later or contact support.';
            }
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'lock_message' => $lockMessage,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): Response
    {
        // This method should normally be intercepted by the logout key on the firewall
        // If we reach here, it means the firewall didn't intercept (e.g., session already expired)
        // In this case, manually clear the session and redirect to login
        
        $reason = $request->query->get('reason', 'manual');
        
        // Clear session if it exists
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->invalidate();
        }
        
        // Add flash message based on reason
        if ($reason === 'session_timeout') {
            $this->addFlash('warning', 'Your session has expired. Please log in again.');
        } else {
            $this->addFlash('success', 'You have been logged out successfully.');
        }
        
        return $this->redirectToRoute('app_login');
    }
}
