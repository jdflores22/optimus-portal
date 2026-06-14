<?php

namespace App\Controller\Consignee;

use App\Entity\ReferralCode;
use App\Repository\ReferralCodeRepository;
use App\Service\ReferralCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/consignee/referral-codes')]
#[IsGranted('ROLE_CONSIGNEE')]
class ReferralCodeController extends AbstractController
{
    public function __construct(
        private ReferralCodeService $referralCodeService,
        private ReferralCodeRepository $referralCodeRepo,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RateLimiterFactory $referralCodeGenerationLimiter
    ) {
    }

    #[Route('', name: 'consignee_referral_codes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('consignee_brokers');
    }

    #[Route('/generate', name: 'consignee_generate_code', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        // Rate limiting
        $limiter = $this->referralCodeGenerationLimiter->create($this->getUser()->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many code generation attempts. Please try again later.');
            return $this->redirectToRoute('consignee_brokers');
        }

        // Validate CSRF token
        $csrfToken = new CsrfToken('generate_referral_code', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('consignee_brokers');
        }

        $user = $this->getUser();
        
        // Get optional parameters
        $maxUses = $request->request->get('max_uses');
        // Default to 1 use per code (one-to-one relationship)
        $maxUses = $maxUses ? (int)$maxUses : 1;
        
        $expiresAt = $request->request->get('expires_at');
        $expiresAt = $expiresAt ? new \DateTime($expiresAt) : null;
        
        // Validate max uses
        if ($maxUses < 1) {
            $this->addFlash('error', 'Maximum uses must be at least 1.');
            return $this->redirectToRoute('consignee_brokers');
        }
        
        // Validate expiry date
        if ($expiresAt && $expiresAt < new \DateTime()) {
            $this->addFlash('error', 'Expiry date must be in the future.');
            return $this->redirectToRoute('consignee_brokers');
        }
        
        try {
            $code = $this->referralCodeService->generateCode($user, $maxUses, $expiresAt);
            
            $this->addFlash('success', "Referral code generated: {$code->getCode()}");
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to generate referral code: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('consignee_brokers');
    }

    #[Route('/{id}/deactivate', name: 'consignee_deactivate_code', methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('deactivate_referral_code', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('consignee_brokers');
        }

        $code = $this->referralCodeRepo->find($id);
        
        if (!$code) {
            throw $this->createNotFoundException('Referral code not found');
        }
        
        // Verify ownership
        if ($code->getConsignee() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not have permission to deactivate this code');
        }
        
        try {
            $this->referralCodeService->deactivateCode($code);
            $this->addFlash('success', 'Referral code deactivated successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to deactivate code: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('consignee_brokers');
    }

    #[Route('/{id}/reactivate', name: 'consignee_reactivate_code', methods: ['POST'])]
    public function reactivate(int $id, Request $request): Response
    {
        // Validate CSRF token
        $csrfToken = new CsrfToken('reactivate_referral_code', $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('consignee_brokers');
        }

        $code = $this->referralCodeRepo->find($id);
        
        if (!$code) {
            throw $this->createNotFoundException('Referral code not found');
        }
        
        // Verify ownership
        if ($code->getConsignee() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not have permission to reactivate this code');
        }
        
        try {
            $this->referralCodeService->reactivateCode($code);
            $this->addFlash('success', 'Referral code reactivated successfully');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to reactivate code: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('consignee_brokers');
    }
}
