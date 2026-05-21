<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class WorkspaceService
{
    private const SESSION_KEY = 'active_workspace_consignee_id';
    private const SESSION_NAME_KEY = 'active_workspace_consignee_name';

    public function __construct(
        private RequestStack $requestStack,
        private BrokerRelationshipService $relationshipService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Set the active workspace (consignee) for a broker
     */
    public function setActiveWorkspace(int $consigneeId, ?User $broker = null): void
    {
        // If broker is provided, validate access and get workspace name
        $workspaceName = 'Workspace';
        if ($broker !== null) {
            if (!$this->validateWorkspaceAccess($broker, $consigneeId)) {
                throw new \InvalidArgumentException('Broker does not have access to this workspace');
            }
            
            // Get workspace name
            $workspaces = $this->getAvailableWorkspaces($broker);
            foreach ($workspaces as $workspace) {
                if ($workspace['id'] === $consigneeId) {
                    $workspaceName = $workspace['name'];
                    break;
                }
            }
        }
        
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $consigneeId);
        $session->set(self::SESSION_NAME_KEY, $workspaceName);
        
        $this->logger->info('Workspace switched', [
            'broker_id' => $broker?->getId(),
            'consignee_id' => $consigneeId,
            'workspace_name' => $workspaceName
        ]);
    }

    /**
     * Get the active workspace (consignee ID) for current session
     */
    public function getActiveWorkspace(): ?int
    {
        $session = $this->requestStack->getSession();
        return $session->get(self::SESSION_KEY);
    }

    /**
     * Clear the active workspace from session
     */
    public function clearActiveWorkspace(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
        $session->remove(self::SESSION_NAME_KEY);
        
        $this->logger->info('Workspace cleared');
    }

    /**
     * Get available workspaces for a broker
     * Supports both NEW (referral code relationships) and OLD (linkedConsignees) systems
     * 
     * @return array<int, array{id: int, name: string, email: string, manifestCount: int, consignee: User}>
     */
    public function getAvailableWorkspaces(User $broker): array
    {
        $workspaces = [];
        $seenConsigneeIds = [];
        
        $this->logger->debug('Getting available workspaces for broker', [
            'broker_id' => $broker->getId(),
            'broker_email' => $broker->getEmail(),
            'broker_class' => get_class($broker)
        ]);
        
        // NEW SYSTEM: Get consignees from referral code relationships
        $relationships = $this->relationshipService->getActiveConsigneesForBroker($broker);
        
        $this->logger->debug('Referral code relationships found', [
            'count' => count($relationships)
        ]);
        
        foreach ($relationships as $relationship) {
            $consignee = $relationship->getConsignee();
            $consigneeId = $consignee->getId();
            
            if (!in_array($consigneeId, $seenConsigneeIds)) {
                $workspaces[] = [
                    'id' => $consigneeId,
                    'name' => method_exists($consignee, 'getBusinessName') 
                        ? $consignee->getBusinessName() 
                        : $consignee->getEmail(),
                    'email' => $consignee->getEmail(),
                    'manifestCount' => 0, // Will be populated below
                    'consignee' => $consignee,
                    'relationship' => $relationship,
                    'source' => 'referral_code'
                ];
                $seenConsigneeIds[] = $consigneeId;
            }
        }
        
        // OLD SYSTEM: Get consignees from linkedConsignees (backward compatibility)
        if (method_exists($broker, 'getLinkedConsignees')) {
            $linkedConsignees = $broker->getLinkedConsignees();
            
            $this->logger->debug('Linked consignees found (old system)', [
                'count' => $linkedConsignees->count(),
                'is_collection' => $linkedConsignees instanceof \Doctrine\Common\Collections\Collection
            ]);
            
            foreach ($linkedConsignees as $consignee) {
                $consigneeId = $consignee->getId();
                
                $this->logger->debug('Processing linked consignee', [
                    'consignee_id' => $consigneeId,
                    'business_name' => method_exists($consignee, 'getBusinessName') ? $consignee->getBusinessName() : 'N/A',
                    'already_added' => in_array($consigneeId, $seenConsigneeIds)
                ]);
                
                // Only add if not already added from new system
                if (!in_array($consigneeId, $seenConsigneeIds)) {
                    $workspaces[] = [
                        'id' => $consigneeId,
                        'name' => method_exists($consignee, 'getBusinessName') 
                            ? $consignee->getBusinessName() 
                            : $consignee->getEmail(),
                        'email' => $consignee->getEmail(),
                        'manifestCount' => 0, // Will be populated below
                        'consignee' => $consignee,
                        'relationship' => null,
                        'source' => 'legacy_link'
                    ];
                    $seenConsigneeIds[] = $consigneeId;
                }
            }
        } else {
            $this->logger->warning('Broker does not have getLinkedConsignees method', [
                'broker_class' => get_class($broker)
            ]);
        }
        
        // Fetch manifest counts for all workspaces in one query
        if (!empty($seenConsigneeIds)) {
            $manifestCounts = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.consignee) as consignee_id, COUNT(m.id) as manifest_count')
                ->from('App\Entity\Manifest', 'm')
                ->where('m.broker = :broker')
                ->andWhere('m.consignee IN (:consignees)')
                ->andWhere('m.archivedForBroker = false')
                ->setParameter('broker', $broker)
                ->setParameter('consignees', $seenConsigneeIds)
                ->groupBy('m.consignee')
                ->getQuery()
                ->getResult();
            
            // Map counts to workspaces
            $countMap = [];
            foreach ($manifestCounts as $row) {
                $countMap[$row['consignee_id']] = (int)$row['manifest_count'];
            }
            
            // Update workspace manifest counts
            foreach ($workspaces as &$workspace) {
                $workspace['manifestCount'] = $countMap[$workspace['id']] ?? 0;
            }
        }
        
        $this->logger->info('Total workspaces retrieved for broker', [
            'broker_id' => $broker->getId(),
            'workspace_count' => count($workspaces),
            'sources' => array_column($workspaces, 'source')
        ]);
        
        return $workspaces;
    }

    /**
     * Validate that a broker has access to a specific workspace
     */
    public function validateWorkspaceAccess(User $broker, int $consigneeId): bool
    {
        $workspaces = $this->getAvailableWorkspaces($broker);
        
        foreach ($workspaces as $workspace) {
            if ($workspace['id'] === $consigneeId) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the active workspace details
     * 
     * @return array{id: int, name: string, email: string, manifestCount: int, consignee: User}|null
     */
    public function getActiveWorkspaceDetails(User $broker): ?array
    {
        $activeWorkspaceId = $this->getActiveWorkspace();
        
        if ($activeWorkspaceId === null) {
            return null;
        }
        
        $workspaces = $this->getAvailableWorkspaces($broker);
        
        foreach ($workspaces as $workspace) {
            if ($workspace['id'] === $activeWorkspaceId) {
                return $workspace;
            }
        }
        
        // Active workspace is invalid, clear it
        $this->clearActiveWorkspace();
        return null;
    }

    /**
     * Check if broker needs to select a workspace
     */
    public function needsWorkspaceSelection(User $broker): bool
    {
        $activeWorkspace = $this->getActiveWorkspace();
        
        // No active workspace set
        if ($activeWorkspace === null) {
            return true;
        }
        
        // Validate current workspace is still valid
        if (!$this->validateWorkspaceAccess($broker, $activeWorkspace)) {
            $this->clearActiveWorkspace();
            return true;
        }
        
        return false;
    }

    /**
     * Auto-select workspace if broker has only one
     * 
     * @return bool True if workspace was auto-selected, false otherwise
     */
    public function autoSelectWorkspace(User $broker): bool
    {
        $workspaces = $this->getAvailableWorkspaces($broker);
        
        if (count($workspaces) === 1) {
            $this->setActiveWorkspace($workspaces[0]['id'], $broker);
            
            $this->logger->info('Workspace auto-selected', [
                'broker_id' => $broker->getId(),
                'consignee_id' => $workspaces[0]['id']
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Get workspace count for a broker
     */
    public function getWorkspaceCount(User $broker): int
    {
        return count($this->getAvailableWorkspaces($broker));
    }

    /**
     * Check if broker has any workspaces
     */
    public function hasWorkspaces(User $broker): bool
    {
        return $this->getWorkspaceCount($broker) > 0;
    }

    /**
     * Switch to next available workspace (useful for testing or cycling)
     */
    public function switchToNextWorkspace(User $broker): ?int
    {
        $workspaces = $this->getAvailableWorkspaces($broker);
        
        if (empty($workspaces)) {
            return null;
        }
        
        $currentWorkspaceId = $this->getActiveWorkspace();
        $workspaceIds = array_column($workspaces, 'id');
        
        if ($currentWorkspaceId === null) {
            // Select first workspace
            $nextWorkspaceId = $workspaceIds[0];
        } else {
            // Find current index and select next
            $currentIndex = array_search($currentWorkspaceId, $workspaceIds);
            $nextIndex = ($currentIndex + 1) % count($workspaceIds);
            $nextWorkspaceId = $workspaceIds[$nextIndex];
        }
        
        $this->setActiveWorkspace($nextWorkspaceId, $broker);
        
        return $nextWorkspaceId;
    }
}
