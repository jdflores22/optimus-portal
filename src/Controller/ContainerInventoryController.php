<?php

namespace App\Controller;

use App\Service\ContainerDataInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ContainerInventoryController extends AbstractController
{
    private const ITEMS_PER_PAGE = 50;

    public function __construct(
        private ContainerDataInterface $containerDataService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/container-inventory', name: 'app_container_inventory')]
    public function index(Request $request): Response
    {
        // Check if user has one of the allowed roles
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        $allowedRoles = ['ROLE_SHIPPING_LINES_ADMIN', 'ROLE_SL_STAFF', 'ROLE_ACCOUNTING', 'ROLE_TERMINAL_TEAM'];
        $hasAccess = false;
        foreach ($allowedRoles as $role) {
            if ($this->isGranted($role)) {
                $hasAccess = true;
                break;
            }
        }
        
        if (!$hasAccess) {
            throw $this->createAccessDeniedException('You do not have permission to access this page.');
        }
        
        // Get current user's shipping line scope
        $user = $this->getUser();
        $shippingLine = $user->getShippingLineScope();
        
        // Get page number from query parameter
        $page = max(1, $request->query->getInt('page', 1));
        
        // Get filter parameters
        $depotFilter = $request->query->get('depot');
        $searchQuery = $request->query->get('search');
        
        // Get total count and paginated container data
        $totalContainers = $this->containerDataService->getContainerCount(
            $depotFilter, // depot filter
            $shippingLine,
            $searchQuery
        );
        
        $containers = $this->containerDataService->getFormattedContainerData(
            $depotFilter, // depot filter
            $shippingLine,
            $page,
            self::ITEMS_PER_PAGE,
            $searchQuery
        );
        
        // Calculate pagination
        $totalPages = (int) ceil($totalContainers / self::ITEMS_PER_PAGE);
        $page = min($page, max(1, $totalPages));
        
        // Get statistics
        $stats = $this->containerDataService->getDetailedStats($depotFilter, $shippingLine, $searchQuery);
        
        // Get stats by terminal identity
        $identityStats = $this->containerDataService->getStatsByTerminalIdentity($depotFilter, $shippingLine, $searchQuery);
        
        // Get capacity by terminal identity
        $capacityStats = $this->containerDataService->getCapacityByTerminalIdentity($shippingLine);
        
        // Get list of all depots (terminals) from database for filter dropdown
        $depots = $this->getAllDepots($shippingLine);
        
        return $this->render('container_inventory/index.html.twig', [
            'containers' => $containers,
            'totalContainers' => $totalContainers,
            'stats' => $stats,
            'identityStats' => $identityStats,
            'capacityStats' => $capacityStats,
            'depots' => $depots,
            'shippingLineName' => $shippingLine?->getBrandName() ?? 'All Shipping Lines',
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'itemsPerPage' => self::ITEMS_PER_PAGE,
            'filters' => [
                'depot' => $depotFilter,
                'search' => $searchQuery
            ]
        ]);
    }
    
    /**
     * Get all depots (terminals) that have containers
     */
    private function getAllDepots(?\App\Entity\ShippingLine $shippingLine): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('DISTINCT t.name')
            ->from(\App\Entity\Terminal::class, 't')
            ->join(\App\Entity\ShippingLineTerminalAllocation::class, 'a', 'WITH', 'a.terminal = t')
            ->join(\App\Entity\Container::class, 'c', 'WITH', 'c.cyAllocation = a')
            ->where('c.allocationStatus IN (:allocationStatuses)')
            ->setParameter('allocationStatuses', [
                \App\Entity\Enum\AllocationStatus::ALLOCATED,
                \App\Entity\Enum\AllocationStatus::PRE_FORECAST
            ])
            ->orderBy('t.name', 'ASC');
        
        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->andWhere('c.shippingLine = :shippingLine')
               ->setParameter('shippingLine', $shippingLine);
        }
        
        $results = $qb->getQuery()->getResult();
        
        // Extract terminal names from results
        $depots = [];
        foreach ($results as $result) {
            $depots[] = $result['name'];
        }
        
        return $depots;
    }
}
