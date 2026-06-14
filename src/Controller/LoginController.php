<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\LoginFormAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class LoginController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        $lockMessage = null;
        $remainingAttempts = null;
        $csrfRefreshMessage = null;
        $user = null;

        $isCsrfError = $error instanceof InvalidCsrfTokenException;

        if ($lastUsername) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $lastUsername]);
        }

        if ($isCsrfError) {
            $csrfRefreshMessage = 'Your sign-in page has expired. Please refresh this page, then enter your email and password again.';
        } elseif ($user && $user->isLocked()) {
            $lockedUntil = $user->getLockedUntil();
            if ($lockedUntil instanceof \DateTimeInterface) {
                $lockMessage = sprintf(
                    'Your account is locked until %s. Please try again later or contact support.',
                    $lockedUntil->format('M j, Y \a\t g:i A')
                );
            } else {
                $lockMessage = 'Your account has been locked due to multiple failed login attempts. Please contact support.';
            }
        } elseif ($error && $user && !$isCsrfError) {
            $attemptsLeft = LoginFormAuthenticator::MAX_FAILED_LOGIN_ATTEMPTS - $user->getFailedLoginAttempts();
            if ($attemptsLeft > 0) {
                $remainingAttempts = $attemptsLeft;
            }
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'lock_message' => $lockMessage,
            'remaining_attempts' => $remainingAttempts,
            'csrf_refresh_message' => $csrfRefreshMessage,
            'show_demo_accounts' => $this->getParameter('kernel.environment') === 'dev',
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): Response
    {
        $reason = $request->query->get('reason', 'manual');

        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        if ($reason === 'session_timeout') {
            $this->addFlash('warning', 'Your session has expired. Please log in again.');
        } else {
            $this->addFlash('success', 'You have been logged out successfully.');
        }

        return $this->redirectToRoute('app_login');
    }
}
