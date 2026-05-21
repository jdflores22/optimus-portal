<?php

namespace App\Controller;

use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Service\FormBuilderService;
use App\Service\UserService;
use App\Service\EmailVerificationService;
use App\Service\ReferralCodeService;
use App\Service\BrokerRelationshipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Service\ValidationService;

class BrokerRegistrationController extends AbstractController
{
    public function __construct(
        private UserService $userService,
        private FormBuilderService $formBuilderService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ValidationService $validationService,
        private EmailVerificationService $emailVerificationService,
        private ReferralCodeService $referralCodeService,
        private BrokerRelationshipService $brokerRelationshipService
    ) {
    }

    #[Route('/register/broker', name: 'broker_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            $csrfToken = new CsrfToken('broker_register', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('broker_register');
            }

            $email = trim(strip_tags($request->request->get('email')));
            $password = $request->request->get('password'); // Don't sanitize passwords
            $confirmPassword = $request->request->get('confirmPassword');
            $fullName = trim(strip_tags($request->request->get('fullName')));
            $referralCode = trim(strip_tags($request->request->get('referralCode', '')));

            // Basic validation
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Please provide a valid email address.');
                return $this->redirectToRoute('broker_register');
            }

            if (empty($password) || strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');
                return $this->redirectToRoute('broker_register');
            }

            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('broker_register');
            }

            if (empty($fullName)) {
                $this->addFlash('error', 'Full name is required.');
                return $this->redirectToRoute('broker_register');
            }

            // Validate referral code if provided
            $validatedReferralCode = null;
            if (!empty($referralCode)) {
                $validation = $this->referralCodeService->validateCode($referralCode);
                
                if (!$validation['valid']) {
                    $this->addFlash('error', $validation['error']);
                    return $this->redirectToRoute('broker_register');
                }
                
                $validatedReferralCode = $validation['referralCode'];
            }

            try {
                // Create broker user
                $user = $this->userService->createUser([
                    'email' => $email,
                    'password' => $password,
                    'fullName' => $fullName
                ], UserRole::BROKER);

                // If referral code was provided and valid, create the relationship
                if ($validatedReferralCode) {
                    $consignee = $validatedReferralCode->getConsignee();
                    $this->brokerRelationshipService->createRelationship(
                        $consignee,
                        $user,
                        $validatedReferralCode
                    );
                    
                    // Increment referral code usage
                    $this->referralCodeService->incrementUsage($validatedReferralCode);
                }

                // Send email verification
                $this->emailVerificationService->sendVerificationEmail($user);

                $successMessage = 'Registration successful! Please check your email and click the verification link to activate your account.';
                if ($validatedReferralCode) {
                    $successMessage .= ' You have been linked to ' . $validatedReferralCode->getConsignee()->getEmail() . '.';
                }
                
                $this->addFlash('success', $successMessage);
                return $this->render('registration/verification_sent.html.twig', [
                    'email' => $email,
                    'userType' => 'broker'
                ]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Registration failed: ' . $e->getMessage());
            }
        }

        return $this->render('registration/broker.html.twig');
    }
}
