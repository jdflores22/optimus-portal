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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
    public function requestOtp(Request $request, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            
            if (!$email) {
                $this->addFlash('error', 'Please provide your email address.');
                return $this->redirectToRoute('forgot_password');
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            
            if ($user) {
                // Generate 6-digit OTP
                $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                
                // Set OTP and expiry
                $expiresAt = new \DateTime('+' . self::OTP_EXPIRY_MINUTES . ' minutes');
                $user->setPasswordResetOtp($otp);
                $user->setPasswordResetOtpExpiresAt($expiresAt);
                
                $this->entityManager->flush();
                
                // Send OTP via email
                try {
                    $this->emailService->sendPasswordResetOtp($user, $otp);
                    
                    // Log password reset request
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
                    
                    // Store email in session for next step
                    $session->set('password_reset_email', $email);
                    
                    $this->addFlash('success', 'A 6-digit OTP has been sent to your email address. Please check your inbox.');
                    return $this->redirectToRoute('forgot_password_verify_otp');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to send OTP. Please try again later.');
                    return $this->redirectToRoute('forgot_password');
                }
            }
            
            // Always show success message for security (don't reveal if email exists)
            $this->addFlash('success', 'If an account with that email exists, you will receive an OTP shortly.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/forgot-password/verify-otp', name: 'forgot_password_verify_otp', methods: ['GET', 'POST'])]
    public function verifyOtp(Request $request, SessionInterface $session): Response
    {
        $email = $session->get('password_reset_email');
        
        if (!$email) {
            $this->addFlash('error', 'Session expired. Please start the password reset process again.');
            return $this->redirectToRoute('forgot_password');
        }

        if ($request->isMethod('POST')) {
            $otp = $request->request->get('otp');
            
            if (!$otp) {
                $this->addFlash('error', 'Please enter the OTP.');
                return $this->redirectToRoute('forgot_password_verify_otp');
            }

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            
            if (!$user) {
                $this->addFlash('error', 'Invalid session. Please start again.');
                $session->remove('password_reset_email');
                return $this->redirectToRoute('forgot_password');
            }

            // Verify OTP
            if ($user->getPasswordResetOtp() === $otp && $user->isPasswordResetOtpValid()) {
                // Log OTP verification
                $this->activityLogService->logActivity(
                    $user,
                    ActivityLog::TYPE_PASSWORD_RESET_OTP_VERIFIED,
                    'User',
                    $user->getId(),
                    null,
                    [
                        'email' => $user->getEmail()
                    ]
                );
                
                // Store verification flag in session
                $session->set('password_reset_otp_verified', true);
                
                $this->addFlash('success', 'OTP verified successfully. You can now reset your password.');
                return $this->redirectToRoute('forgot_password_reset');
            } else {
                $this->addFlash('error', 'Invalid or expired OTP. Please try again.');
                return $this->redirectToRoute('forgot_password_verify_otp');
            }
        }

        return $this->render('security/verify_otp.html.twig', [
            'email' => $email
        ]);
    }

    #[Route('/forgot-password/reset', name: 'forgot_password_reset', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request, SessionInterface $session): Response
    {
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

            // Hash and set new password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
            $user->setPasswordHash($hashedPassword);
            
            // Clear OTP
            $user->setPasswordResetOtp(null);
            $user->setPasswordResetOtpExpiresAt(null);
            
            // Reset failed login attempts
            $user->resetFailedLoginAttempts();
            $user->setLockedUntil(null);
            
            $this->entityManager->flush();
            
            // Log password reset completion
            $this->activityLogService->logActivity(
                $user,
                ActivityLog::TYPE_PASSWORD_RESET_COMPLETED,
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail()
                ]
            );
            
            // Send password change confirmation email
            try {
                $this->emailService->sendPasswordChangeConfirmation($user);
            } catch (\Exception $e) {
                // Log error but don't fail the password reset
                // The password has already been changed successfully
                error_log('Failed to send password change confirmation email: ' . $e->getMessage());
            }
            
            // Clear session
            $session->remove('password_reset_email');
            $session->remove('password_reset_otp_verified');
            
            $this->addFlash('success', 'Your password has been reset successfully. You can now log in with your new password.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'email' => $email
        ]);
    }

    #[Route('/forgot-password/resend-otp', name: 'forgot_password_resend_otp', methods: ['POST'])]
    public function resendOtp(SessionInterface $session): Response
    {
        $email = $session->get('password_reset_email');
        
        if (!$email) {
            $this->addFlash('error', 'Session expired. Please start the password reset process again.');
            return $this->redirectToRoute('forgot_password');
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        
        if ($user) {
            // Generate new OTP
            $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            
            // Set OTP and expiry
            $expiresAt = new \DateTime('+' . self::OTP_EXPIRY_MINUTES . ' minutes');
            $user->setPasswordResetOtp($otp);
            $user->setPasswordResetOtpExpiresAt($expiresAt);
            
            $this->entityManager->flush();
            
            // Send OTP via email
            try {
                $this->emailService->sendPasswordResetOtp($user, $otp);
                
                // Log OTP resend
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
}