<?php

namespace App\Controller;

use App\Service\ContainerDataInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DetailedStackViewController extends AbstractController
{
    private const ITEMS_PER_PAGE = 20;

    public function __construct(
        private ContainerDataInterface $containerDataService
    ) {
    }

    #[Route('/shipping-admin/depot/{depotId}/detailed-stack', name: 'app_detailed_stack_view')]
    #[IsGranted('ROLE_SHIPPING_LINES_ADMIN')]
    public function detailedStackView(string $depotId, Request $request): Response
    {
        // Get current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // Get page number from query parameter
        $page = max(1, $request->query->getInt('page', 1));
        
        // Check if filtering by specific container
        $containerNumber = $request->query->get('container');
        
        // Get total count and paginated container data
        $totalContainers = $this->containerDataService->getContainerCount($depotId, $shippingLine, $containerNumber);
        $sampleContainers = $this->containerDataService->getFormattedContainerData(
            $depotId, 
            $shippingLine,
            $page,
            self::ITEMS_PER_PAGE,
            $containerNumber
        );
        
        // Calculate pagination
        $totalPages = (int) ceil($totalContainers / self::ITEMS_PER_PAGE);
        $page = min($page, max(1, $totalPages)); // Ensure page is within valid range
        
        // Get depot full name
        $depotFullName = $this->containerDataService->getDepotFullName($depotId);

        // Calculate total TEU count (for all containers, not just current page)
        $totalTEU = $this->containerDataService->calculateTotalTEUForQuery($depotId, $shippingLine, $containerNumber);

        // Get detailed stats for all containers (not just current page)
        $stats = $this->containerDataService->getDetailedStats($depotId, $shippingLine, $containerNumber);

        // Get capacity information from terminal allocation
        $capacity = $this->containerDataService->getTerminalCapacity($depotId, $user);

        return $this->render('detailed_stack/view.html.twig', [
            'depotId' => $depotId,
            'depotName' => $depotFullName,
            'containers' => $sampleContainers,
            'totalTEU' => $totalTEU,
            'containerCount' => $totalContainers,
            'stats' => $stats,
            'capacity' => $capacity,
            'shippingLineName' => $shippingLine?->getBrandName() ?? 'All Shipping Lines',
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'containerNumber' => $containerNumber,
            'isContainerDetail' => $containerNumber !== null
        ]);
    }

    #[Route('/terminal-team/depot/{depotId}/detailed-stack', name: 'app_terminal_team_detailed_stack_view')]
    #[IsGranted('ROLE_TERMINAL_TEAM')]
    public function terminalTeamDetailedStackView(string $depotId, Request $request): Response
    {
        // Terminal team uses the same shipping line scope as their admin
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // Get page number from query parameter
        $page = max(1, $request->query->getInt('page', 1));
        
        // Check if filtering by specific container
        $containerNumber = $request->query->get('container');
        
        // Get total count and paginated container data
        $totalContainers = $this->containerDataService->getContainerCount($depotId, $shippingLine, $containerNumber);
        $sampleContainers = $this->containerDataService->getFormattedContainerData(
            $depotId, 
            $shippingLine,
            $page,
            self::ITEMS_PER_PAGE,
            $containerNumber
        );
        
        // Calculate pagination
        $totalPages = (int) ceil($totalContainers / self::ITEMS_PER_PAGE);
        $page = min($page, max(1, $totalPages)); // Ensure page is within valid range
        
        // Get depot full name
        $depotFullName = $this->containerDataService->getDepotFullName($depotId);

        // Calculate total TEU count (for all containers, not just current page)
        $totalTEU = $this->containerDataService->calculateTotalTEUForQuery($depotId, $shippingLine, $containerNumber);

        // Get detailed stats for all containers (not just current page)
        $stats = $this->containerDataService->getDetailedStats($depotId, $shippingLine, $containerNumber);

        // Get capacity information from terminal allocation
        $capacity = $this->containerDataService->getTerminalCapacity($depotId, $user);

        return $this->render('detailed_stack/view.html.twig', [
            'depotId' => $depotId,
            'depotName' => $depotFullName,
            'containers' => $sampleContainers,
            'totalTEU' => $totalTEU,
            'containerCount' => $totalContainers,
            'stats' => $stats,
            'capacity' => $capacity,
            'shippingLineName' => $shippingLine?->getBrandName() ?? 'All Shipping Lines',
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'containerNumber' => $containerNumber,
            'isContainerDetail' => $containerNumber !== null,
            'isTerminalTeam' => true
        ]);
    }
}