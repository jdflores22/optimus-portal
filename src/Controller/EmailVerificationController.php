<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmailVerificationController extends AbstractController
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/email/verify/{token}', name: 'email_verify', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        try {
            $user = $this->emailVerificationService->verifyEmail($token);

            if (!$user) {
                $this->addFlash('error', 'Invalid or expired verification link. Please request a new verification email.');
                return $this->redirectToRoute('app_login');
            }

            $this->addFlash('success', 'Email verified successfully! Your account is now pending approval. You will receive a notification once approved.');
            return $this->render('email_verification/success.html.twig', [
                'user' => $user
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'An error occurred during email verification. Please try again or contact support.');
            return $this->redirectToRoute('app_login');
        }
    }

    #[Route('/email/resend', name: 'email_verification_resend', methods: ['GET', 'POST'])]
    public function resendVerification(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            
            if (!$email) {
                $this->addFlash('error', 'Please provide your email address.');
                return $this->redirectToRoute('email_verification_resend');
            }

            // Find user by email
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if (!$user) {
                // Don't reveal if email exists or not for security
                $this->addFlash('success', 'If an account with that email exists and needs verification, a new verification email has been sent.');
                return $this->redirectToRoute('app_login');
            }

            if ($user->isEmailVerified()) {
                $this->addFlash('info', 'Your email is already verified. You can log in to your account.');
                return $this->redirectToRoute('app_login');
            }

            try {
                $this->emailVerificationService->resendVerificationEmail($user);
                $this->addFlash('success', 'A new verification email has been sent to your email address.');
                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to send verification email. Please try again later.');
            }
        }

        return $this->render('email_verification/resend.html.twig');
    }
}