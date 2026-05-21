<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\ShippingLine;
use App\Entity\Enum\UserRole;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Comprehensive activity logging service for ALL system activities
 * Ensures real-time, synchronous logging for every system operation
 * 
 * CRITICAL: This service MUST log ALL user activities for audit compliance
 */
class ActivityLogService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogRepository $activityLogRepository,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Core logging method - ALL activities go through this
     * 
     * @param User $user The user performing the activity
     * @param string $activityType The type of activity (use ActivityLog constants)
     * @param string|null $entityType The type of entity being acted upon
     * @param int|null $entityId The ID of the entity being acted upon
     * @param array|null $oldValues Previous values for update operations
     * @param array|null $newValues New values for update operations
     * @param array|null $additionalContext Additional context information
     */
    public function logActivity(
        User $user,
        string $activityType,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $additionalContext = null
    ): void {
        try {
            $request = $this->requestStack->getCurrentRequest();
            
            $activityLog = new ActivityLog();
            $activityLog->setUser($user);
            $activityLog->setActivityType($activityType);
            $activityLog->setShippingLine($user->getShippingLineScope());
            
            if ($entityType !== null) {
                $activityLog->setEntityType($entityType);
            }
            
            if ($entityId !== null) {
                $activityLog->setEntityId($entityId);
            }
            
            if ($oldValues !== null) {
                $activityLog->setOldValues($oldValues);
            }
            
            if ($newValues !== null) {
                $activityLog->setNewValues($newValues);
            }
            
            if ($additionalContext !== null) {
                $activityLog->setAdditionalContext($additionalContext);
            }

            // Set request information if available
            if ($request !== null) {
                $activityLog->setIpAddress($request->getClientIp() ?? '127.0.0.1');
                $activityLog->setUserAgent($request->headers->get('User-Agent'));
                $activityLog->setSessionId($request->getSession()->getId());
            } else {
                $activityLog->setIpAddress('127.0.0.1'); // Default for CLI operations
            }

            $this->entityManager->persist($activityLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log the error but don't fail the main operation
            error_log('Failed to log activity: ' . $e->getMessage());
        }
    }

    // ==================== AUTHENTICATION & SESSION ACTIVITIES ====================

    /**
     * Log user login activity
     */
    public function logLogin(User $user, string $ipAddress, string $userAgent): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_LOGIN,
            'User',
            $user->getId(),
            null,
            null,
            ['ip_address' => $ipAddress, 'user_agent' => $userAgent]
        );
    }

    /**
     * Log user logout activity
     */
    public function logLogout(User $user): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_LOGOUT,
            'User',
            $user->getId()
        );
    }

    /**
     * Log failed login attempt
     */
    public function logFailedLogin(string $email, string $ipAddress): void
    {
        // For failed logins, we create a log without a user
        try {
            $request = $this->requestStack->getCurrentRequest();
            
            $activityLog = new ActivityLog();
            // We need a user for the foreign key constraint, so we'll use a system user or handle this differently
            // For now, we'll skip failed logins without a valid user
            // This should be handled by creating a system user for such cases
            
            // Alternative: Store failed login attempts in a separate table or use a system user
            error_log("Failed login attempt for email: {$email} from IP: {$ipAddress}");
        } catch (\Exception $e) {
            error_log('Failed to log failed login: ' . $e->getMessage());
        }
    }

    /**
     * Log password change activity
     */
    public function logPasswordChange(User $user): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PASSWORD_CHANGE,
            'User',
            $user->getId()
        );
    }

    /**
     * Log session timeout
     */
    public function logSessionTimeout(User $user): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SESSION_TIMEOUT,
            'User',
            $user->getId()
        );
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * Log entity creation
     */
    public function logCreate(User $user, object $entity): void
    {
        $entityType = $this->getEntityType($entity);
        $entityId = $this->getEntityId($entity);
        
        $this->logActivity(
            $user,
            ActivityLog::TYPE_CREATE,
            $entityType,
            $entityId,
            null,
            $this->serializeEntity($entity)
        );
    }

    /**
     * Log entity update
     */
    public function logUpdate(User $user, object $entity, array $changes): void
    {
        $entityType = $this->getEntityType($entity);
        $entityId = $this->getEntityId($entity);
        
        $this->logActivity(
            $user,
            ActivityLog::TYPE_UPDATE,
            $entityType,
            $entityId,
            $changes['old'] ?? null,
            $changes['new'] ?? null
        );
    }

    /**
     * Log entity deletion
     */
    public function logDelete(User $user, object $entity): void
    {
        $entityType = $this->getEntityType($entity);
        $entityId = $this->getEntityId($entity);
        
        $this->logActivity(
            $user,
            ActivityLog::TYPE_DELETE,
            $entityType,
            $entityId,
            $this->serializeEntity($entity),
            null
        );
    }

    /**
     * Log entity view/access
     */
    public function logView(User $user, object $entity): void
    {
        $entityType = $this->getEntityType($entity);
        $entityId = $this->getEntityId($entity);
        
        $this->logActivity(
            $user,
            ActivityLog::TYPE_VIEW,
            $entityType,
            $entityId
        );
    }

    // ==================== USER MANAGEMENT ACTIVITIES ====================

    /**
     * Log user creation
     */
    public function logUserCreation(User $actor, User $newUser): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_USER_CREATION,
            'User',
            $newUser->getId(),
            null,
            [
                'email' => $newUser->getEmail(),
                'role' => $newUser->getRole()->value,
                'status' => $newUser->getStatus()->value
            ]
        );
    }

    /**
     * Log user suspension
     */
    public function logUserSuspension(User $actor, User $targetUser): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_USER_SUSPENSION,
            'User',
            $targetUser->getId(),
            ['status' => 'active'],
            ['status' => 'suspended']
        );
    }

    /**
     * Log user activation
     */
    public function logUserActivation(User $actor, User $targetUser): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_USER_ACTIVATION,
            'User',
            $targetUser->getId(),
            ['status' => 'suspended'],
            ['status' => 'active']
        );
    }

    /**
     * Log role change
     */
    public function logRoleChange(User $actor, User $targetUser, string $oldRole, string $newRole): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_ROLE_CHANGE,
            'User',
            $targetUser->getId(),
            ['role' => $oldRole],
            ['role' => $newRole]
        );
    }

    /**
     * Log hierarchy change
     */
    public function logHierarchyChange(User $actor, User $child, ?User $oldParent, ?User $newParent): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_HIERARCHY_CHANGE,
            'User',
            $child->getId(),
            ['parent_id' => $oldParent?->getId(), 'parent_email' => $oldParent?->getEmail()],
            ['parent_id' => $newParent?->getId(), 'parent_email' => $newParent?->getEmail()]
        );
    }

    // ==================== SHIPPING LINE MANAGEMENT ====================

    /**
     * Log shipping line creation
     */
    public function logShippingLineCreation(User $actor, ShippingLine $shippingLine): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_SHIPPING_LINE_CREATION,
            'ShippingLine',
            $shippingLine->getId(),
            null,
            [
                'brand_name' => $shippingLine->getBrandName(),
                'portal_config' => $shippingLine->getPortalConfig()
            ]
        );
    }

    /**
     * Log shipping line update
     */
    public function logShippingLineUpdate(User $actor, ShippingLine $shippingLine, array $changes): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_SHIPPING_LINE_UPDATE,
            'ShippingLine',
            $shippingLine->getId(),
            $changes['old'] ?? null,
            $changes['new'] ?? null
        );
    }

    /**
     * Log shipping line deactivation
     */
    public function logShippingLineDeactivation(User $actor, ShippingLine $shippingLine): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_SHIPPING_LINE_DEACTIVATION,
            'ShippingLine',
            $shippingLine->getId(),
            ['is_active' => true],
            ['is_active' => false]
        );
    }

    /**
     * Log shipping line activation
     */
    public function logShippingLineActivation(User $actor, ShippingLine $shippingLine): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_SHIPPING_LINE_ACTIVATION,
            'ShippingLine',
            $shippingLine->getId(),
            ['is_active' => false],
            ['is_active' => true]
        );
    }

    /**
     * Log admin assignment to shipping line
     */
    public function logAdminAssignment(User $actor, User $admin, ShippingLine $shippingLine): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_ADMIN_ASSIGNMENT,
            'ShippingLine',
            $shippingLine->getId(),
            null,
            [
                'admin_id' => $admin->getId(),
                'admin_email' => $admin->getEmail(),
                'shipping_line_name' => $shippingLine->getBrandName()
            ]
        );
    }

    // ==================== DATA ACCESS & OPERATIONS ====================

    /**
     * Log search operations
     */
    public function logSearch(User $user, string $searchTerm, array $results, string $context = 'general'): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SEARCH,
            null,
            null,
            null,
            null,
            [
                'search_term' => $searchTerm,
                'result_count' => count($results),
                'context' => $context
            ]
        );
    }

    /**
     * Log data export operations
     */
    public function logExport(User $user, string $exportType, array $filters, int $recordCount): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_EXPORT,
            null,
            null,
            null,
            null,
            [
                'export_type' => $exportType,
                'filters' => $filters,
                'record_count' => $recordCount
            ]
        );
    }

    /**
     * Log data import operations
     */
    public function logImport(User $user, string $importType, int $recordCount, array $summary): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_IMPORT,
            null,
            null,
            null,
            null,
            [
                'import_type' => $importType,
                'record_count' => $recordCount,
                'summary' => $summary
            ]
        );
    }

    /**
     * Log report generation
     */
    public function logReportGeneration(User $user, string $reportType, array $parameters): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_REPORT_GENERATION,
            null,
            null,
            null,
            null,
            [
                'report_type' => $reportType,
                'parameters' => $parameters
            ]
        );
    }

    // ==================== CONFIGURATION CHANGES ====================

    /**
     * Log configuration changes
     */
    public function logConfigChange(User $user, string $configKey, $oldValue, $newValue): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_CONFIG_CHANGE,
            'Configuration',
            null,
            [$configKey => $oldValue],
            [$configKey => $newValue]
        );
    }

    /**
     * Log permission changes
     */
    public function logPermissionChange(User $user, User $targetUser, array $oldPermissions, array $newPermissions): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PERMISSION_CHANGE,
            'User',
            $targetUser->getId(),
            $oldPermissions,
            $newPermissions
        );
    }

    /**
     * Log branding changes
     */
    public function logBrandingChange(User $user, ShippingLine $shippingLine, array $changes): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_BRANDING_CHANGE,
            'ShippingLine',
            $shippingLine->getId(),
            $changes['old'] ?? null,
            $changes['new'] ?? null
        );
    }

    // ==================== SYSTEM OPERATIONS ====================

    /**
     * Log system maintenance operations
     */
    public function logSystemMaintenance(User $user, string $operation, array $details): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SYSTEM_MAINTENANCE,
            null,
            null,
            null,
            null,
            [
                'operation' => $operation,
                'details' => $details
            ]
        );
    }

    /**
     * Log database migration operations
     */
    public function logDatabaseMigration(User $user, string $migration, string $status): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_DATABASE_MIGRATION,
            null,
            null,
            null,
            null,
            [
                'migration' => $migration,
                'status' => $status
            ]
        );
    }

    /**
     * Log backup operations
     */
    public function logBackupOperation(User $user, string $backupType, string $status): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_BACKUP_OPERATION,
            null,
            null,
            null,
            null,
            [
                'backup_type' => $backupType,
                'status' => $status
            ]
        );
    }

    // ==================== SECURITY EVENTS ====================

    /**
     * Log access denied events
     */
    public function logAccessDenied(User $user, string $resource, string $reason): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_ACCESS_DENIED,
            null,
            null,
            null,
            null,
            [
                'resource' => $resource,
                'reason' => $reason
            ]
        );
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(User $user, string $activity, array $details): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            null,
            null,
            null,
            null,
            [
                'activity' => $activity,
                'details' => $details
            ]
        );
    }

    /**
     * Log privilege escalation attempts
     */
    public function logPrivilegeEscalationAttempt(User $user, string $attemptedAction): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
            null,
            null,
            null,
            null,
            [
                'attempted_action' => $attemptedAction
            ]
        );
    }

    /**
     * Log cross-shipping line access attempts
     */
    public function logCrossShippingLineAccess(User $user, string $resource, int $attemptedShippingLineId): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            null,
            null,
            null,
            null,
            [
                'activity' => 'cross_shipping_line_access_attempt',
                'resource' => $resource,
                'attempted_shipping_line_id' => $attemptedShippingLineId,
                'user_shipping_line_id' => $user->getShippingLineScope()?->getId()
            ]
        );
    }

    /**
     * Log security policy violations
     */
    public function logSecurityPolicyViolation(User $user, string $policy, array $details): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            null,
            null,
            null,
            null,
            [
                'activity' => 'security_policy_violation',
                'policy' => $policy,
                'details' => $details
            ]
        );
    }

    /**
     * Log authentication failures with enhanced details
     */
    public function logAuthenticationFailure(string $email, string $reason, string $ipAddress): void
    {
        // For failed logins without a valid user, we'll log to error_log
        // In a production system, this should go to a separate security log
        error_log(sprintf(
            'Authentication failure - Email: %s, Reason: %s, IP: %s, Time: %s',
            $email,
            $reason,
            $ipAddress,
            (new \DateTime())->format('Y-m-d H:i:s')
        ));
    }

    // ==================== BUSINESS OPERATIONS ====================

    /**
     * Log pre-advice creation
     */
    public function logPreAdviceCreation(User $user, object $preAdvice): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PRE_ADVICE_CREATION,
            $this->getEntityType($preAdvice),
            $this->getEntityId($preAdvice),
            null,
            $this->serializeEntity($preAdvice)
        );
    }

    /**
     * Log payment processing
     */
    public function logPaymentProcessing(User $user, object $payment): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PAYMENT_PROCESSING,
            $this->getEntityType($payment),
            $this->getEntityId($payment),
            null,
            $this->serializeEntity($payment)
        );
    }

    /**
     * Log document upload
     */
    public function logDocumentUpload(User $user, string $documentType, string $filename): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_DOCUMENT_UPLOAD,
            null,
            null,
            null,
            null,
            [
                'document_type' => $documentType,
                'filename' => $filename
            ]
        );
    }

    /**
     * Log status changes
     */
    public function logStatusChange(User $user, object $entity, string $oldStatus, string $newStatus): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_STATUS_CHANGE,
            $this->getEntityType($entity),
            $this->getEntityId($entity),
            ['status' => $oldStatus],
            ['status' => $newStatus]
        );
    }

    // ==================== TERMINAL OPERATIONS (TERMINAL_TEAM specific) ====================

    /**
     * Log container assignment
     */
    public function logContainerAssignment(User $user, object $container, string $terminal): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_CONTAINER_ASSIGNMENT,
            $this->getEntityType($container),
            $this->getEntityId($container),
            null,
            null,
            [
                'terminal' => $terminal,
                'container_number' => $this->getContainerNumber($container)
            ]
        );
    }

    /**
     * Log terminal allocation
     */
    public function logTerminalAllocation(User $user, object $allocation): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_TERMINAL_ALLOCATION,
            $this->getEntityType($allocation),
            $this->getEntityId($allocation),
            null,
            $this->serializeEntity($allocation)
        );
    }

    /**
     * Log container movement
     */
    public function logContainerMovement(User $user, object $container, string $fromLocation, string $toLocation): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_CONTAINER_MOVEMENT,
            $this->getEntityType($container),
            $this->getEntityId($container),
            ['location' => $fromLocation],
            ['location' => $toLocation],
            [
                'container_number' => $this->getContainerNumber($container)
            ]
        );
    }

    /**
     * Log yard assignment
     */
    public function logYardAssignment(User $user, object $container, string $yardLocation): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_YARD_ASSIGNMENT,
            $this->getEntityType($container),
            $this->getEntityId($container),
            null,
            null,
            [
                'yard_location' => $yardLocation,
                'container_number' => $this->getContainerNumber($container)
            ]
        );
    }

    /**
     * Log port terminal assignment
     */
    public function logPortTerminalAssignment(User $user, object $container, string $portTerminal): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PORT_TERMINAL_ASSIGNMENT,
            $this->getEntityType($container),
            $this->getEntityId($container),
            null,
            null,
            [
                'port_terminal' => $portTerminal,
                'container_number' => $this->getContainerNumber($container)
            ]
        );
    }

    // ==================== ADDITIONAL LOGGING METHODS ====================

    /**
     * Log user reassignment (for orphaned user cleanup)
     */
    public function logUserReassignment(User $actor, User $user, User $oldAdmin, User $newAdmin): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_HIERARCHY_CHANGE,
            'User',
            $user->getId(),
            [
                'old_admin_id' => $oldAdmin->getId(),
                'old_admin_email' => $oldAdmin->getEmail()
            ],
            [
                'new_admin_id' => $newAdmin->getId(),
                'new_admin_email' => $newAdmin->getEmail()
            ],
            ['operation' => 'user_reassignment']
        );
    }

    /**
     * Log user deletion
     */
    public function logUserDeletion(User $actor, User $targetUser): void
    {
        $this->logActivity(
            $actor,
            ActivityLog::TYPE_DELETE,
            'User',
            $targetUser->getId(),
            [
                'email' => $targetUser->getEmail(),
                'role' => $targetUser->getRole()->value,
                'status' => $targetUser->getStatus()->value
            ],
            null
        );
    }

    // ==================== MANIFEST WORKFLOW OPERATIONS ====================

    /**
     * Log manifest upload
     */
    public function logManifestUpload(User $user, object $manifest, string $filename): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_UPLOADED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'vessel_name' => method_exists($manifest, 'getVesselName') ? $manifest->getVesselName() : null,
                'voyage_number' => method_exists($manifest, 'getVoyageNumber') ? $manifest->getVoyageNumber() : null,
                'filename' => $filename
            ]
        );
    }

    /**
     * Log manifest consignee declaration
     */
    public function logManifestConsigneeDeclaration(User $user, object $manifest, array $consigneeData): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_CONSIGNEE_DECLARED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'consignee_count' => count($consigneeData),
                'consignees' => array_map(fn($c) => $c['email'] ?? $c['id'] ?? null, $consigneeData)
            ]
        );
    }

    /**
     * Log manifest state transition
     */
    public function logManifestStateTransition(User $user, object $manifest, string $oldState, string $newState): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_STATE_TRANSITION,
            'Manifest',
            $this->getEntityId($manifest),
            ['state' => $oldState],
            ['state' => $newState],
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null
            ]
        );
    }

    /**
     * Log manifest payment submission
     */
    public function logManifestPaymentSubmission(User $user, object $payment, object $manifest): void
    {
        $paymentType = null;
        if (method_exists($payment, 'getPaymentType')) {
            $type = $payment->getPaymentType();
            $paymentType = $type ? $type->value : null;
        }
        
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_PAYMENT_SUBMITTED,
            'Payment',
            $this->getEntityId($payment),
            null,
            [
                'manifest_id' => $this->getEntityId($manifest),
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'payment_type' => $paymentType,
                'amount' => method_exists($payment, 'getAmount') ? $payment->getAmount() : null
            ]
        );
    }

    /**
     * Log manifest payment validation
     */
    public function logManifestPaymentValidation(User $user, object $payment, object $manifest, bool $approved): void
    {
        $activityType = $approved ? ActivityLog::TYPE_MANIFEST_PAYMENT_VALIDATED : ActivityLog::TYPE_MANIFEST_PAYMENT_REJECTED;
        
        $paymentType = null;
        if (method_exists($payment, 'getPaymentType')) {
            $type = $payment->getPaymentType();
            $paymentType = $type ? $type->value : null;
        }
        
        $this->logActivity(
            $user,
            $activityType,
            'Payment',
            $this->getEntityId($payment),
            ['status' => 'pending'],
            ['status' => $approved ? 'approved' : 'rejected'],
            [
                'manifest_id' => $this->getEntityId($manifest),
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'payment_type' => $paymentType
            ]
        );
    }

    /**
     * Log NOA generation
     */
    public function logNOAGeneration(User $user, object $manifest): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_NOA_GENERATED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'vessel_name' => method_exists($manifest, 'getVesselName') ? $manifest->getVesselName() : null,
                'voyage_number' => method_exists($manifest, 'getVoyageNumber') ? $manifest->getVoyageNumber() : null
            ]
        );
    }

    /**
     * Log Billing generation
     */
    public function logBillingGeneration(User $user, object $billing, object $manifest): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_BILLING_GENERATED,
            'Billing',
            $this->getEntityId($billing),
            null,
            [
                'manifest_id' => $this->getEntityId($manifest),
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'billing_number' => method_exists($billing, 'getBillingNumber') ? $billing->getBillingNumber() : null,
                'total_amount' => method_exists($billing, 'getTotalAmount') ? $billing->getTotalAmount() : null
            ]
        );
    }

    /**
     * Log EDO generation
     */
    public function logEDOGeneration(User $user, object $manifest): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_EDO_GENERATED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'vessel_name' => method_exists($manifest, 'getVesselName') ? $manifest->getVesselName() : null
            ]
        );
    }

    /**
     * Log Bill of Lading upload
     */
    public function logBLUpload(User $user, object $manifest, string $filename): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_BL_UPLOADED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'filename' => $filename
            ]
        );
    }

    /**
     * Log manifest document download
     */
    public function logManifestDocumentDownload(User $user, object $manifest, string $documentType): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_MANIFEST_DOCUMENT_DOWNLOADED,
            'Manifest',
            $this->getEntityId($manifest),
            null,
            null,
            [
                'manifest_number' => method_exists($manifest, 'getManifestNumber') ? $manifest->getManifestNumber() : null,
                'document_type' => $documentType
            ]
        );
    }

    // ==================== QUERY AND RETRIEVAL METHODS ====================

    /**
     * Get activity history for a user with optional scope and date filtering
     */
    public function getActivityHistory(User $user, ?ShippingLine $scope = null, ?\DateTime $from = null, ?\DateTime $to = null, array $additionalFilters = []): array
    {
        $filters = [
            'user_id' => $user->getId(),
        ];

        if ($scope) {
            $filters['shipping_line_id'] = $scope->getId();
        }

        if ($from) {
            $filters['from_date'] = $from;
        }

        if ($to) {
            $filters['to_date'] = $to;
        }

        return $this->activityLogRepository->searchWithFilters($filters);
    }

    /**
     * Get user activity summary for a date range
     */
    public function getUserActivitySummary(User $user, \DateTime $from, \DateTime $to): array
    {
        return $this->activityLogRepository->getUserActivitySummary($user, $from, $to);
    }

    /**
     * Get shipping line activity report
     */
    public function getShippingLineActivityReport(ShippingLine $shippingLine, \DateTime $from, \DateTime $to): array
    {
        return $this->activityLogRepository->findByDateRange($from, $to, $shippingLine);
    }

    /**
     * Get system-wide activity (SYSTEM_ADMIN only)
     */
    public function getSystemWideActivity(\DateTime $from, \DateTime $to): array
    {
        return $this->activityLogRepository->findByDateRange($from, $to);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get entity type name from object
     */
    private function getEntityType(object $entity): string
    {
        $reflection = new \ReflectionClass($entity);
        return $reflection->getShortName();
    }

    /**
     * Get entity ID from object
     */
    private function getEntityId(object $entity): ?int
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }
        return null;
    }

    /**
     * Serialize entity for logging (basic implementation)
     */
    private function serializeEntity(object $entity): array
    {
        $data = [];
        
        if (method_exists($entity, 'getId')) {
            $data['id'] = $entity->getId();
        }
        
        // Add more serialization logic based on entity types
        if ($entity instanceof User) {
            $data['email'] = $entity->getEmail();
            $data['role'] = $entity->getRole()->value;
        } elseif ($entity instanceof ShippingLine) {
            $data['brand_name'] = $entity->getBrandName();
            $data['is_active'] = $entity->isActive();
        }
        
        return $data;
    }

    /**
     * Get container number from container entity
     */
    private function getContainerNumber(object $container): ?string
    {
        if (method_exists($container, 'getContainerNumber')) {
            return $container->getContainerNumber();
        }
        if (method_exists($container, 'getNumber')) {
            return $container->getNumber();
        }
        return null;
    }

    /**
     * Search activity logs with text search and filters
     */
    public function searchActivityLogs(User $user, string $searchTerm, ?ShippingLine $scope = null, array $filters = []): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(ActivityLog::class, 'al')
            ->leftJoin('al.user', 'u')
            ->leftJoin('al.shippingLine', 'sl')
            ->orderBy('al.createdAt', 'DESC');

        // Apply scope filtering based on user role
        if ($user->getRole() !== UserRole::SYSTEM_ADMIN) {
            if ($scope) {
                $qb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
                   ->setParameter('scope', $scope);
            } else {
                $qb->andWhere('al.user = :user')
                   ->setParameter('user', $user);
            }
        }

        // Text search
        if (!empty($searchTerm)) {
            $qb->andWhere('
                al.activityType LIKE :searchTerm OR 
                al.entityType LIKE :searchTerm OR 
                u.email LIKE :searchTerm OR 
                sl.brandName LIKE :searchTerm OR
                al.ipAddress LIKE :searchTerm
            ')
            ->setParameter('searchTerm', '%' . $searchTerm . '%');
        }

        // Apply additional filters
        if (!empty($filters['activityType'])) {
            $qb->andWhere('al.activityType = :activityType')
               ->setParameter('activityType', $filters['activityType']);
        }
        if (!empty($filters['entityType'])) {
            $qb->andWhere('al.entityType = :entityType')
               ->setParameter('entityType', $filters['entityType']);
        }
        if (!empty($filters['userId'])) {
            $qb->andWhere('al.user = :filterUser')
               ->setParameter('filterUser', $filters['userId']);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('al.createdAt >= :from')
               ->setParameter('from', new \DateTime($filters['from']));
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('al.createdAt <= :to')
               ->setParameter('to', new \DateTime($filters['to']));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Generate summary report for activity logs
     */
    public function generateSummaryReport(User $user, ?ShippingLine $scope, \DateTime $from, \DateTime $to): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('al.activityType, COUNT(al.id) as count')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('al.activityType')
            ->orderBy('count', 'DESC');

        // Apply scope filtering
        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $qb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
               ->setParameter('scope', $scope);
        }

        $activityCounts = $qb->getQuery()->getResult();

        // Get total activities
        $totalQb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(al.id)')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $totalQb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
                    ->setParameter('scope', $scope);
        }

        $totalActivities = $totalQb->getQuery()->getSingleScalarResult();

        // Get unique users count
        $usersQb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT al.user)')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $usersQb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
                    ->setParameter('scope', $scope);
        }

        $uniqueUsers = $usersQb->getQuery()->getSingleScalarResult();

        return [
            'totalActivities' => $totalActivities,
            'uniqueUsers' => $uniqueUsers,
            'activityBreakdown' => $activityCounts,
            'dateRange' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]
        ];
    }

    /**
     * Generate security report for activity logs
     */
    public function generateSecurityReport(User $user, ?ShippingLine $scope, \DateTime $from, \DateTime $to): array
    {
        $securityTypes = [
            ActivityLog::TYPE_LOGIN,
            ActivityLog::TYPE_LOGOUT,
            ActivityLog::TYPE_FAILED_LOGIN,
            ActivityLog::TYPE_ACCESS_DENIED,
            ActivityLog::TYPE_SUSPICIOUS_ACTIVITY,
            ActivityLog::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
        ];

        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->andWhere('al.activityType IN (:securityTypes)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('securityTypes', $securityTypes)
            ->orderBy('al.createdAt', 'DESC');

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $qb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
               ->setParameter('scope', $scope);
        }

        $securityEvents = $qb->getQuery()->getResult();

        // Count by type
        $countQb = $this->entityManager->createQueryBuilder()
            ->select('al.activityType, COUNT(al.id) as count')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->andWhere('al.activityType IN (:securityTypes)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('securityTypes', $securityTypes)
            ->groupBy('al.activityType');

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $countQb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
                    ->setParameter('scope', $scope);
        }

        $securityCounts = $countQb->getQuery()->getResult();

        return [
            'securityEvents' => $securityEvents,
            'securityCounts' => $securityCounts,
            'totalSecurityEvents' => count($securityEvents),
            'dateRange' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]
        ];
    }

    /**
     * Generate user activity report
     */
    public function generateUserActivityReport(User $user, ?ShippingLine $scope, \DateTime $from, \DateTime $to): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u.email, u.role, COUNT(al.id) as activityCount')
            ->from(ActivityLog::class, 'al')
            ->join('al.user', 'u')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('u.id')
            ->orderBy('activityCount', 'DESC');

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $qb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
               ->setParameter('scope', $scope);
        }

        $userActivities = $qb->getQuery()->getResult();

        return [
            'userActivities' => $userActivities,
            'dateRange' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]
        ];
    }

    /**
     * Generate business operations report
     */
    public function generateBusinessOperationsReport(User $user, ?ShippingLine $scope, \DateTime $from, \DateTime $to): array
    {
        $businessTypes = [
            ActivityLog::TYPE_PRE_ADVICE_CREATION,
            ActivityLog::TYPE_PAYMENT_PROCESSING,
            ActivityLog::TYPE_DOCUMENT_UPLOAD,
            ActivityLog::TYPE_STATUS_CHANGE,
            ActivityLog::TYPE_CONTAINER_ASSIGNMENT,
            ActivityLog::TYPE_TERMINAL_ALLOCATION,
            ActivityLog::TYPE_CONTAINER_MOVEMENT,
            ActivityLog::TYPE_YARD_ASSIGNMENT,
            ActivityLog::TYPE_PORT_TERMINAL_ASSIGNMENT,
        ];

        $qb = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->andWhere('al.activityType IN (:businessTypes)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('businessTypes', $businessTypes)
            ->orderBy('al.createdAt', 'DESC');

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $qb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
               ->setParameter('scope', $scope);
        }

        $businessEvents = $qb->getQuery()->getResult();

        // Count by type
        $countQb = $this->entityManager->createQueryBuilder()
            ->select('al.activityType, COUNT(al.id) as count')
            ->from(ActivityLog::class, 'al')
            ->where('al.createdAt >= :from AND al.createdAt <= :to')
            ->andWhere('al.activityType IN (:businessTypes)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('businessTypes', $businessTypes)
            ->groupBy('al.activityType');

        if ($user->getRole() !== UserRole::SYSTEM_ADMIN && $scope) {
            $countQb->andWhere('al.shippingLine = :scope OR al.shippingLine IS NULL')
                    ->setParameter('scope', $scope);
        }

        $businessCounts = $countQb->getQuery()->getResult();

        return [
            'businessEvents' => $businessEvents,
            'businessCounts' => $businessCounts,
            'totalBusinessEvents' => count($businessEvents),
            'dateRange' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d')
            ]
        ];
    }

    /**
     * Log system operation (for integrity checks, etc.)
     */
    public function logSystemOperation(User $user, string $operation, array $details): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_SYSTEM_MAINTENANCE,
            'system_operation',
            null,
            null,
            $details,
            $this->getClientIpAddress(),
            $this->getUserAgent()
        );
    }

    /**
     * Log eDO payment submission
     */
    public function logEDOPaymentSubmission(User $user, object $edoPayment, object $manifest): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PAYMENT_PROCESSING,
            $this->getEntityType($edoPayment),
            $this->getEntityId($edoPayment),
            null,
            null,
            [
                'edo_payment_id' => $edoPayment->getId(),
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'amount' => $edoPayment->getAmount(),
                'payment_type' => 'edo_access',
                'description' => sprintf(
                    'Submitted eDO payment for manifest %s (Amount: ₱%s)',
                    $manifest->getManifestNumber(),
                    number_format($edoPayment->getAmount(), 2)
                )
            ]
        );
    }

    /**
     * Log eDO payment validation
     */
    public function logEDOPaymentValidation(User $user, object $edoPayment, object $manifest, bool $approved): void
    {
        $this->logActivity(
            $user,
            ActivityLog::TYPE_PAYMENT_PROCESSING,
            $this->getEntityType($edoPayment),
            $this->getEntityId($edoPayment),
            null,
            null,
            [
                'edo_payment_id' => $edoPayment->getId(),
                'manifest_id' => $manifest->getId(),
                'manifest_number' => $manifest->getManifestNumber(),
                'approved' => $approved,
                'rejection_reason' => $edoPayment->getRejectionReason(),
                'payment_type' => 'edo_access',
                'description' => sprintf(
                    '%s eDO payment for manifest %s',
                    $approved ? 'Approved' : 'Rejected',
                    $manifest->getManifestNumber()
                )
            ]
        );
    }
}