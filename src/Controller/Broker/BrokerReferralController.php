<?php

namespace App\Controller\Broker;

use App\Service\ReferralCodeService;
use App\Service\BrokerRelationshipService;
use App\Service\ActivityLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/broker/referral')]
#[IsGranted('ROLE_BROKER')]
class BrokerReferralController extends AbstractController
{
    public function __construct(
        private ReferralCodeService $referralCodeService,
        private BrokerRelationshipService $brokerRelationshipService,
        private ActivityLogService $activityLogService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.referral_code_application')]
        private RateLimiterFactory $referralCodeApplicationLimiter
    ) {
    }

    #[Route('/apply', name: 'broker_apply_referral_code', methods: ['GET', 'POST'])]
    public function applyCode(Request $request): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            // Rate limiting
            $limiter = $this->referralCodeApplicationLimiter->create($user->getId());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Too many attempts. Please try again later.');
                return $this->redirectToRoute('broker_apply_referral_code');
            }

            // Validate CSRF token
            $csrfToken = new CsrfToken('apply_referral_code', $request->request->get('_csrf_token'));
            if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('broker_apply_referral_code');
            }

            $code = trim($request->request->get('referral_code', ''));

            if (empty($code)) {
                $this->addFlash('error', 'Please enter a referral code.');
                return $this->redirectToRoute('broker_apply_referral_code');
            }

            // Validate the referral code
            $validation = $this->referralCodeService->validateCode($code);

            if (!$validation['valid']) {
                // Log failed attempt
                $this->activityLogService->logActivity(
                    $user,
                    'broker_referral_code_failed',
                    'Broker',
                    $user->getId(),
                    [
                        'code_attempted' => $code,
                        'error' => $validation['error'],
                        'ip_address' => $request->getClientIp()
                    ]
                );
                
                $this->addFlash('error', $validation['error']);
                return $this->redirectToRoute('broker_apply_referral_code');
            }

            $referralCode = $validation['referralCode'];
            $consignee = $referralCode->getConsignee();

            // Check if relationship already exists
            if ($this->brokerRelationshipService->hasActiveRelationship($consignee, $user)) {
                // Log duplicate attempt
                $this->activityLogService->logActivity(
                    $user,
                    'broker_referral_code_duplicate',
                    'Broker',
                    $user->getId(),
                    [
                        'code' => $code,
                        'consignee_id' => $consignee->getId(),
                        'consignee_name' => $consignee->getBusinessName(),
                        'ip_address' => $request->getClientIp()
                    ]
                );
                
                $this->addFlash('warning', 'You are already linked to ' . $consignee->getBusinessName());
                return $this->redirectToRoute('broker_workspace_selector');
            }

            // Double-check that the code hasn't been used yet (race condition protection)
            if ($referralCode->hasReachedMaxUses()) {
                // Log already used attempt
                $this->activityLogService->logActivity(
                    $user,
                    'broker_referral_code_already_used',
                    'Broker',
                    $user->getId(),
                    [
                        'code' => $code,
                        'consignee_id' => $consignee->getId(),
                        'consignee_name' => $consignee->getBusinessName(),
                        'ip_address' => $request->getClientIp()
                    ]
                );
                
                $this->addFlash('error', 'This referral code has already been used and is no longer valid.');
                return $this->redirectToRoute('broker_apply_referral_code');
            }

            try {
                // Increment referral code usage FIRST to prevent race conditions
                $this->referralCodeService->incrementUsage($referralCode);

                // Create the relationship
                $this->brokerRelationshipService->createRelationship(
                    $consignee,
                    $user,
                    $referralCode
                );

                // Log successful application
                $this->activityLogService->logActivity(
                    $user,
                    'broker_referral_code_applied',
                    'Broker',
                    $user->getId(),
                    [
                        'code' => $code,
                        'referral_code_id' => $referralCode->getId(),
                        'consignee_id' => $consignee->getId(),
                        'consignee_name' => $consignee->getBusinessName(),
                        'ip_address' => $request->getClientIp()
                    ]
                );

                $this->addFlash('success', 'Successfully linked to ' . $consignee->getBusinessName() . '! You can now access their manifests.');
                return $this->redirectToRoute('broker_workspace_selector');
            } catch (\Exception $e) {
                // Log exception
                $this->activityLogService->logActivity(
                    $user,
                    'broker_referral_code_error',
                    'Broker',
                    $user->getId(),
                    [
                        'code' => $code,
                        'error' => $e->getMessage(),
                        'ip_address' => $request->getClientIp()
                    ]
                );
                
                $this->addFlash('error', 'Failed to apply referral code: ' . $e->getMessage());
                return $this->redirectToRoute('broker_apply_referral_code');
            }
        }

        return $this->render('broker/referral/apply.html.twig');
    }
}
