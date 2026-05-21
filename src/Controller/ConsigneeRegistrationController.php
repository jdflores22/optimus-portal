<?php

namespace App\Controller;

use App\Entity\Enum\FormType;
use App\Entity\Enum\UserRole;
use App\Service\FormBuilderService;
use App\Service\UserService;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Service\ValidationService;

class ConsigneeRegistrationController extends AbstractController
{
    public function __construct(
        private UserService $userService,
        private FormBuilderService $formBuilderService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ValidationService $validationService,
        private EmailVerificationService $emailVerificationService
    ) {
    }

    #[Route('/register/consignee', name: 'consignee_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            // Validate CSRF token
            $csrfToken = new CsrfToken('consignee_register', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('consignee_register');
            }

            $email = trim(strip_tags($request->request->get('email')));
            $password = $request->request->get('password'); // Don't sanitize passwords
            $confirmPassword = $request->request->get('confirmPassword');
            $businessName = trim(strip_tags($request->request->get('businessName')));

            // Basic validation
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Please provide a valid email address.');
                return $this->redirectToRoute('consignee_register');
            }

            if (empty($password) || strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters long.');
                return $this->redirectToRoute('consignee_register');
            }

            if ($password !== $confirmPassword) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('consignee_register');
            }

            if (empty($businessName)) {
                $this->addFlash('error', 'Business name is required.');
                return $this->redirectToRoute('consignee_register');
            }

            try {
                // Create consignee user
                $user = $this->userService->createUser([
                    'email' => $email,
                    'password' => $password,
                    'businessName' => $businessName
                ], UserRole::CONSIGNEE);

                // Send email verification
                $this->emailVerificationService->sendVerificationEmail($user);

                $this->addFlash('success', 'Registration successful! Please check your email and click the verification link to activate your account.');
                return $this->render('registration/verification_sent.html.twig', [
                    'email' => $email,
                    'userType' => 'consignee'
                ]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Registration failed: ' . $e->getMessage());
            }
        }

        return $this->render('registration/consignee.html.twig');
    }
}
