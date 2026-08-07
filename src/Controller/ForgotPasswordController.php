<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\ActivityLog;
use App\Service\ActivityLogService;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ForgotPasswordController extends AbstractController
{
    private const OTP_EXPIRY_MINUTES = 15;
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailNotificationService $emailService,
        private ActivityLogService $activityLogService,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function requestOtp(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            
            if ($email === '') {
                $this->addFlash('error', 'Please provide your email address.');
                return $this->redirectToRoute('forgot_password');
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            
            if ($user) {
                $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = new \DateTime('+' . self::OTP_EXPIRY_MINUTES . ' minutes');
                $user->setPasswordResetOtp($otp);
                $user->setPasswordResetOtpExpiresAt($expiresAt);
                
                $this->entityManager->flush();
                
                try {
                    $this->emailService->sendPasswordResetOtp($user, $otp);
                    
                    $this->activityLogService->logActivity(
                        $user,
                        ActivityLog::TYPE_PASSWORD_RESET_REQUESTED,
                        'User',
                        $user->getId(),
                        null,
                        [
                            'email' => $user->getEmail(),
                            'otp_expires_at' => $expiresAt->format('Y-m-d H:i:s')
                        ]
                    );
                    
                    $request->getSession()->set('password_reset_email', $email);
                    
                    $this->addFlash('success', 'A 6-digit OTP has been sent to your email address. Please check your inbox.');
                    return $this->redirectToRoute('forgot_password_verify_otp');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to send OTP. Please try again later.');
                    return $this->redirectToRoute('forgot_password');
                }
            }
            
            $this->addFlash('success', 'If an account with that email exists, you will receive an OTP shortly.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/forgot-password/verify-otp', name: 'forgot_password_verify_otp', methods: ['GET', 'POST'])]
    public function verifyOtp(Request $request): Response
    {
        $session = $request->getSession();
        $email = $session->get('password_reset_email');
        
        if (!$email) {
            $this->addFlash('error', 'Session expired. Please start the password reset process again.');
            return $this->redirectToRoute('forgot_password');
        }

        if ($request->isMethod('POST')) {
            $otp = trim((string) $request->request->get('otp', ''));
            
            if ($otp === '') {
                $this->addFlash('error', 'Please enter the OTP.');
                return $this->redirectToRoute('forgot_password_verify_otp');
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            
            if (!$user) {
                $this->addFlash('error', 'Invalid session. Please start again.');
                $session->remove('password_reset_email');
                $session->remove('password_reset_otp_verified');
                return $this->redirectToRoute('forgot_password');
            }

            $storedOtp = (string) $user->getPasswordResetOtp();
            if (!hash_equals($storedOtp, $otp) || !$user->isPasswordResetOtpValid()) {
                $this->addFlash('error', 'Invalid or expired OTP. Please try again.');
                return $this->redirectToRoute('forgot_password_verify_otp');
            }

            $this->activityLogService->logActivity(
                $user,
                ActivityLog::TYPE_PASSWORD_RESET_OTP_VERIFIED,
                'User',
                $user->getId(),
                null,
                ['email' => $user->getEmail()]
            );

            $session->set('password_reset_otp_verified', true);

            $this->addFlash('success', 'OTP verified successfully. You can now reset your password.');
            return $this->redirectToRoute('forgot_password_reset');
        }

        return $this->render('security/verify_otp.html.twig', [
            'email' => $email
        ]);
    }

    #[Route('/forgot-password/reset', name: 'forgot_password_reset', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request): Response
    {
        $session = $request->getSession();
        $email = $session->get('password_reset_email');
        $otpVerified = $session->get('password_reset_otp_verified');
        
        if (!$email || !$otpVerified) {
            $this->addFlash('error', 'Unauthorized access. Please complete the OTP verification first.');
            return $this->redirectToRoute('forgot_password');
        }

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');
            
            if (!$newPassword || !$confirmPassword) {
                $this->addFlash('error', 'Please fill in all fields.');
                return $this->redirectToRoute('forgot_password_reset');
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('forgot_password_reset');
            }

            if (strlen($newPassword) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');
                return $this->redirectToRoute('forgot_password_reset');
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            
            if (!$user) {
                $this->addFlash('error', 'User not found.');
                $session->remove('password_reset_email');
                $session->remove('password_reset_otp_verified');
                return $this->redirectToRoute('forgot_password');
            }

            return $this->completePasswordReset($request, $user, $newPassword);
        }

        return $this->render('security/reset_password.html.twig', [
            'email' => $email
        ]);
    }

    #[Route('/forgot-password/resend-otp', name: 'forgot_password_resend_otp', methods: ['POST'])]
    public function resendOtp(Request $request): Response
    {
        $session = $request->getSession();
        $email = $session->get('password_reset_email');
        
        if (!$email) {
            $this->addFlash('error', 'Session expired. Please start the password reset process again.');
            return $this->redirectToRoute('forgot_password');
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        
        if ($user) {
            $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = new \DateTime('+' . self::OTP_EXPIRY_MINUTES . ' minutes');
            $user->setPasswordResetOtp($otp);
            $user->setPasswordResetOtpExpiresAt($expiresAt);
            
            $this->entityManager->flush();
            
            try {
                $this->emailService->sendPasswordResetOtp($user, $otp);
                
                $this->activityLogService->logActivity(
                    $user,
                    ActivityLog::TYPE_PASSWORD_RESET_REQUESTED,
                    'User',
                    $user->getId(),
                    null,
                    [
                        'email' => $user->getEmail(),
                        'otp_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                        'resend' => true
                    ]
                );
                
                $this->addFlash('success', 'A new OTP has been sent to your email address.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to send OTP. Please try again later.');
            }
        }
        
        return $this->redirectToRoute('forgot_password_verify_otp');
    }

    private function completePasswordReset(Request $request, User $user, string $newPassword): Response
    {
        $session = $request->getSession();

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPasswordHash($hashedPassword);
        $user->setPasswordResetOtp(null);
        $user->setPasswordResetOtpExpiresAt(null);
        $user->resetFailedLoginAttempts();
        $user->setLockedUntil(null);

        $this->entityManager->flush();

        $this->activityLogService->logActivity(
            $user,
            ActivityLog::TYPE_PASSWORD_RESET_COMPLETED,
            'User',
            $user->getId(),
            null,
            ['email' => $user->getEmail()]
        );

        try {
            $this->emailService->sendPasswordChangeConfirmation($user);
        } catch (\Exception $e) {
            error_log('Failed to send password change confirmation email: ' . $e->getMessage());
        }

        $session->remove('password_reset_email');
        $session->remove('password_reset_otp_verified');

        $this->addFlash('success', 'Your password has been reset successfully. You can now log in with your new password.');

        return $this->redirectToRoute('app_login');
    }
}
