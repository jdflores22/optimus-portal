<?php

namespace App\Controller;

use App\Entity\ShippingLine;
use App\Service\ShippingLineAccessControlService;
use App\Service\ShippingLineContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/shipping-line')]
#[IsGranted('ROLE_USER')]
class ShippingLineSelectionController extends AbstractController
{
    public function __construct(
        private ShippingLineContextService $contextService,
        private ShippingLineAccessControlService $accessControl,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Display shipping line selection page with card grid
     */
    #[Route('/select', name: 'app_shipping_line_select')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get all shipping lines
        $allShippingLines = $this->entityManager
            ->getRepository(ShippingLine::class)
            ->findAll();
        
        // Get accessible shipping lines for the user
        $accessibleShippingLines = $this->accessControl->getAccessibleShippingLines($user);
        
        // Build shipping line data with access status
        $shippingLineData = [];
        foreach ($allShippingLines as $shippingLine) {
            $canAccess = $this->accessControl->canAccessShippingLine($user, $shippingLine);
            $hasApprovedAccreditation = $this->accessControl->hasApprovedAccreditation($user, $shippingLine);
            
            // Determine status
            $status = 'NOT_APPLIED';
            if ($hasApprovedAccreditation) {
                $status = 'APPROVED';
            } elseif ($canAccess) {
                // Check if there's a pending or rejected accreditation
                $accreditationRepo = $this->entityManager->getRepository(\App\Entity\AccreditationSubmission::class);
                $submission = $accreditationRepo->findByApplicantAndShippingLine($user, $shippingLine->getId());
                
                if ($submission) {
                    $status = $submission->getStatus()->value;
                }
            }
            
            $shippingLineData[] = [
                'entity' => $shippingLine,
                'canAccess' => $canAccess,
                'status' => $status,
            ];
        }
        
        // Get current shipping line
        $currentShippingLine = $this->contextService->getCurrentShippingLine($user);
        
        return $this->render('shipping_line/select.html.twig', [
            'shippingLines' => $shippingLineData,
            'currentShippingLine' => $currentShippingLine,
        ]);
    }

    /**
     * Select a shipping line (initial selection)
     */
    #[Route('/select/{id}', name: 'app_shipping_line_select_action', methods: ['POST'])]
    public function selectShippingLine(int $id): Response
    {
        $user = $this->getUser();
        
        $shippingLine = $this->entityManager
            ->getRepository(ShippingLine::class)
            ->find($id);
        
        if (!$shippingLine) {
            $this->addFlash('error', 'Shipping line not found.');
            return $this->redirectToRoute('app_shipping_line_select');
        }
        
        // Check if user has access
        if (!$this->accessControl->canAccessShippingLine($user, $shippingLine)) {
            $this->addFlash('error', 'You do not have access to this shipping line.');
            return $this->redirectToRoute('app_shipping_line_select');
        }
        
        // Set current shipping line
        $this->contextService->setCurrentShippingLine($user, $shippingLine);
        
        $this->addFlash('success', sprintf('Switched to %s', $shippingLine->getBrandName()));
        
        // Redirect to role-based dashboard
        return $this->redirectToRoute('app_role_dashboard');
    }

    /**
     * Switch to a different shipping line
     */
    #[Route('/switch/{id}', name: 'app_shipping_line_switch', methods: ['POST'])]
    public function switchShippingLine(int $id): Response
    {
        $user = $this->getUser();
        
        $shippingLine = $this->entityManager
            ->getRepository(ShippingLine::class)
            ->find($id);
        
        if (!$shippingLine) {
            $this->addFlash('error', 'Shipping line not found.');
            return $this->redirectToRoute('app_shipping_line_select');
        }
        
        // Check if user has access
        if (!$this->accessControl->canAccessShippingLine($user, $shippingLine)) {
            $this->addFlash('error', 'You do not have access to this shipping line.');
            return $this->redirectToRoute('app_shipping_line_select');
        }
        
        // Switch shipping line
        $this->contextService->switchShippingLine($user, $shippingLine->getId());
        
        $this->addFlash('success', sprintf('Switched to %s', $shippingLine->getBrandName()));
        
        // Redirect back to referrer or dashboard
        $referer = $this->getReferer();
        if ($referer) {
            return $this->redirect($referer);
        }
        
        return $this->redirectToRoute('app_role_dashboard');
    }

    /**
     * Get the HTTP referer URL
     */
    private function getReferer(): ?string
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        return $request?->headers->get('referer');
    }
}
