<?php

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Table(name: 'activity_logs')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_activity_logs_user_activity')]
#[ORM\Index(columns: ['shipping_line_id', 'created_at'], name: 'idx_activity_logs_shipping_line_activity')]
#[ORM\Index(columns: ['activity_type', 'created_at'], name: 'idx_activity_logs_activity_type')]
#[ORM\Index(columns: ['entity_type', 'entity_id', 'created_at'], name: 'idx_activity_logs_entity_activity')]
#[ORM\Index(columns: ['created_at'], name: 'idx_activity_logs_created_at')]
#[ORM\Index(columns: ['session_id'], name: 'idx_activity_logs_session')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_activity_logs_ip_address')]
class ActivityLog
{
    // Activity Type Constants - ALL system activities
    public const TYPE_LOGIN = 'login';
    public const TYPE_LOGOUT = 'logout';
    public const TYPE_FAILED_LOGIN = 'failed_login';
    public const TYPE_PASSWORD_CHANGE = 'password_change';
    public const TYPE_PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    public const TYPE_PASSWORD_RESET_OTP_VERIFIED = 'password_reset_otp_verified';
    public const TYPE_PASSWORD_RESET_COMPLETED = 'password_reset_completed';
    public const TYPE_SESSION_TIMEOUT = 'session_timeout';
    
    // CRUD Operations
    public const TYPE_CREATE = 'create';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';
    public const TYPE_VIEW = 'view';
    
    // Data Operations
    public const TYPE_SEARCH = 'search';
    public const TYPE_EXPORT = 'export';
    public const TYPE_IMPORT = 'import';
    public const TYPE_REPORT_GENERATION = 'report_generation';
    
    // User Management
    public const TYPE_USER_CREATION = 'user_creation';
    public const TYPE_USER_SUSPENSION = 'user_suspension';
    public const TYPE_USER_ACTIVATION = 'user_activation';
    public const TYPE_USER_UNLOCKED = 'user_unlocked';
    public const TYPE_USER_ACCOUNT_LOCKED_FAILED_ATTEMPTS = 'user_account_locked_failed_attempts';
    public const TYPE_ROLE_CHANGE = 'role_change';
    public const TYPE_HIERARCHY_CHANGE = 'hierarchy_change';
    public const TYPE_USER_INVITATION_CREATED = 'USER_INVITATION_CREATED';
    public const TYPE_USER_INVITATION_SENT = 'USER_INVITATION_SENT';
    public const TYPE_USER_ROLE_ACCEPTED = 'USER_ROLE_ACCEPTED';
    public const TYPE_USER_ROLE_DECLINED = 'USER_ROLE_DECLINED';
    
    // Shipping Line Management
    public const TYPE_SHIPPING_LINE_CREATION = 'shipping_line_creation';
    public const TYPE_SHIPPING_LINE_UPDATE = 'shipping_line_update';
    public const TYPE_SHIPPING_LINE_ACTIVATION = 'shipping_line_activation';
    public const TYPE_SHIPPING_LINE_DEACTIVATION = 'shipping_line_deactivation';
    public const TYPE_ADMIN_ASSIGNMENT = 'admin_assignment';
    
    // Configuration Changes
    public const TYPE_CONFIG_CHANGE = 'config_change';
    public const TYPE_PERMISSION_CHANGE = 'permission_change';
    public const TYPE_BRANDING_CHANGE = 'branding_change';
    
    // System Operations
    public const TYPE_SYSTEM_MAINTENANCE = 'system_maintenance';
    public const TYPE_DATABASE_MIGRATION = 'database_migration';
    public const TYPE_BACKUP_OPERATION = 'backup_operation';
    
    // Security Events
    public const TYPE_ACCESS_DENIED = 'access_denied';
    public const TYPE_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const TYPE_PRIVILEGE_ESCALATION_ATTEMPT = 'privilege_escalation_attempt';
    
    // Business Operations
    public const TYPE_PRE_ADVICE_CREATION = 'pre_advice_creation';
    public const TYPE_PAYMENT_PROCESSING = 'payment_processing';
    public const TYPE_DOCUMENT_UPLOAD = 'document_upload';
    public const TYPE_STATUS_CHANGE = 'status_change';
    
    // Terminal Operations (TERMINAL_TEAM specific)
    public const TYPE_CONTAINER_ASSIGNMENT = 'container_assignment';
    public const TYPE_TERMINAL_ALLOCATION = 'terminal_allocation';
    public const TYPE_CONTAINER_MOVEMENT = 'container_movement';
    public const TYPE_YARD_ASSIGNMENT = 'yard_assignment';
    public const TYPE_PORT_TERMINAL_ASSIGNMENT = 'port_terminal_assignment';
    
    // Container Library Management
    public const TYPE_CONTAINER_TYPE_CREATED = 'container_type_created';
    public const TYPE_CONTAINER_TYPE_UPDATED = 'container_type_updated';
    public const TYPE_CONTAINER_TYPE_DELETED = 'container_type_deleted';
    public const TYPE_CONTAINER_SIZE_CREATED = 'container_size_created';
    public const TYPE_CONTAINER_SIZE_UPDATED = 'container_size_updated';
    public const TYPE_CONTAINER_SIZE_DELETED = 'container_size_deleted';
    
    // Manifest Workflow Operations
    public const TYPE_MANIFEST_UPLOADED = 'manifest_uploaded';
    public const TYPE_MANIFEST_CONSIGNEE_DECLARED = 'manifest_consignee_declared';
    public const TYPE_MANIFEST_STATE_TRANSITION = 'manifest_state_transition';
    public const TYPE_MANIFEST_PAYMENT_SUBMITTED = 'manifest_payment_submitted';
    public const TYPE_MANIFEST_PAYMENT_VALIDATED = 'manifest_payment_validated';
    public const TYPE_MANIFEST_PAYMENT_REJECTED = 'manifest_payment_rejected';
    public const TYPE_MANIFEST_NOA_GENERATED = 'manifest_noa_generated';
    public const TYPE_MANIFEST_BILLING_GENERATED = 'manifest_billing_generated';
    public const TYPE_MANIFEST_EDO_GENERATED = 'manifest_edo_generated';
    public const TYPE_MANIFEST_BL_UPLOADED = 'manifest_bl_uploaded';
    public const TYPE_MANIFEST_DOCUMENT_DOWNLOADED = 'manifest_document_downloaded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'User is required for activity log')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'activityLogs')]
    #[ORM\JoinColumn(name: 'shipping_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLine $shippingLine = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Activity type is required')]
    #[Assert\Length(max: 50, maxMessage: 'Activity type cannot be longer than {{ limit }} characters')]
    private string $activityType;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Entity type cannot be longer than {{ limit }} characters')]
    private ?string $entityType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $oldValues = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $newValues = null;

    #[ORM\Column(type: 'string', length: 45)]
    #[Assert\NotBlank(message: 'IP address is required')]
    #[Assert\Ip(message: 'Invalid IP address format')]
    private string $ipAddress;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $additionalContext = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getShippingLine(): ?ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(?ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }

    public function getActivityType(): string
    {
        return $this->activityType;
    }

    public function setActivityType(string $activityType): self
    {
        $this->activityType = $activityType;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(?string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getOldValues(): ?array
    {
        return $this->oldValues;
    }

    public function setOldValues(?array $oldValues): self
    {
        $this->oldValues = $oldValues;
        return $this;
    }

    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    public function setNewValues(?array $newValues): self
    {
        $this->newValues = $newValues;
        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getAdditionalContext(): ?array
    {
        return $this->additionalContext;
    }

    public function setAdditionalContext(?array $additionalContext): self
    {
        $this->additionalContext = $additionalContext;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    // Business Logic Methods

    /**
     * Checks if this activity log is within a specific shipping line scope
     */
    public function isInShippingLineScope(?ShippingLine $shippingLine): bool
    {
        if ($shippingLine === null) {
            return true; // System admin can see all
        }
        
        return $this->shippingLine === $shippingLine;
    }

    /**
     * Gets a human-readable description of the activity
     */
    public function getActivityDescription(): string
    {
        $descriptions = [
            self::TYPE_LOGIN => 'User logged in',
            self::TYPE_LOGOUT => 'User logged out',
            self::TYPE_FAILED_LOGIN => 'Failed login attempt',
            self::TYPE_PASSWORD_CHANGE => 'Password changed',
            self::TYPE_PASSWORD_RESET_REQUESTED => 'Password Reset Requested',
            self::TYPE_PASSWORD_RESET_OTP_VERIFIED => 'Password Reset OTP Verified',
            self::TYPE_PASSWORD_RESET_COMPLETED => 'Password Reset Completed',
            self::TYPE_CREATE => 'Created ' . ($this->entityType ?? 'entity'),
            self::TYPE_UPDATE => 'Updated ' . ($this->entityType ?? 'entity'),
            self::TYPE_DELETE => 'Deleted ' . ($this->entityType ?? 'entity'),
            self::TYPE_VIEW => 'Viewed ' . ($this->entityType ?? 'entity'),
            self::TYPE_SEARCH => 'Performed search',
            self::TYPE_EXPORT => 'Exported data',
            self::TYPE_USER_CREATION => 'Created new user',
            self::TYPE_USER_INVITATION_CREATED => 'User invitation created',
            self::TYPE_USER_INVITATION_SENT => 'User invitation sent',
            self::TYPE_USER_ROLE_ACCEPTED => 'User role accepted',
            self::TYPE_USER_ROLE_DECLINED => 'User role declined',
            self::TYPE_USER_UNLOCKED => 'User Account Unlocked',
            self::TYPE_USER_ACCOUNT_LOCKED_FAILED_ATTEMPTS => 'Account Locked - Failed Login Attempts',
            self::TYPE_ROLE_CHANGE => 'Changed user role',
            self::TYPE_SHIPPING_LINE_CREATION => 'Created shipping line',
            self::TYPE_SHIPPING_LINE_UPDATE => 'Shipping Line Update',
            self::TYPE_SHIPPING_LINE_ACTIVATION => 'Activated shipping line',
            self::TYPE_SHIPPING_LINE_DEACTIVATION => 'Deactivated shipping line',
            self::TYPE_USER_ACTIVATION => 'User Activation',
            self::TYPE_ACCESS_DENIED => 'Access denied',
            self::TYPE_CONTAINER_ASSIGNMENT => 'Assigned container',
            self::TYPE_TERMINAL_ALLOCATION => 'Allocated terminal space',
            'terminal_created' => 'Terminal Created',
            'terminal_updated' => 'Terminal Updated',
            'terminal_status_changed' => 'Terminal Status Changed',
            'terminal_deleted' => 'Terminal Deleted',
            'container_yard_allocation_created' => 'Container Yard Allocated',
            'container_yard_allocation_updated' => 'Container Yard Allocation Updated',
            'container_yard_allocation_removed' => 'Container Yard Allocation Removed',
            self::TYPE_CONTAINER_TYPE_CREATED => 'Container Type Created',
            self::TYPE_CONTAINER_TYPE_UPDATED => 'Container Type Updated',
            self::TYPE_CONTAINER_TYPE_DELETED => 'Container Type Deactivated',
            self::TYPE_CONTAINER_SIZE_CREATED => 'Container Size Created',
            self::TYPE_CONTAINER_SIZE_UPDATED => 'Container Size Updated',
            self::TYPE_CONTAINER_SIZE_DELETED => 'Container Size Deactivated',
            self::TYPE_MANIFEST_UPLOADED => 'Manifest Uploaded',
            self::TYPE_MANIFEST_CONSIGNEE_DECLARED => 'Manifest Consignee Declared',
            self::TYPE_MANIFEST_STATE_TRANSITION => 'Manifest State Changed',
            self::TYPE_MANIFEST_PAYMENT_SUBMITTED => 'Manifest Payment Submitted',
            self::TYPE_MANIFEST_PAYMENT_VALIDATED => 'Manifest Payment Validated',
            self::TYPE_MANIFEST_PAYMENT_REJECTED => 'Manifest Payment Rejected',
            self::TYPE_MANIFEST_NOA_GENERATED => 'Notice of Arrival Generated',
            self::TYPE_MANIFEST_BILLING_GENERATED => 'Billing Document Generated',
            self::TYPE_MANIFEST_EDO_GENERATED => 'Electronic Delivery Order Generated',
            self::TYPE_MANIFEST_BL_UPLOADED => 'Bill of Lading Uploaded',
            self::TYPE_MANIFEST_DOCUMENT_DOWNLOADED => 'Manifest Document Downloaded',
        ];

        return $descriptions[$this->activityType] ?? 'Unknown activity';
    }

    /**
     * Checks if this is a security-related activity
     */
    public function isSecurityActivity(): bool
    {
        $securityTypes = [
            self::TYPE_LOGIN,
            self::TYPE_LOGOUT,
            self::TYPE_FAILED_LOGIN,
            self::TYPE_ACCESS_DENIED,
            self::TYPE_SUSPICIOUS_ACTIVITY,
            self::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
        ];

        return in_array($this->activityType, $securityTypes);
    }

    /**
     * Checks if this is a business operation activity
     */
    public function isBusinessActivity(): bool
    {
        $businessTypes = [
            self::TYPE_PRE_ADVICE_CREATION,
            self::TYPE_PAYMENT_PROCESSING,
            self::TYPE_DOCUMENT_UPLOAD,
            self::TYPE_STATUS_CHANGE,
            self::TYPE_CONTAINER_ASSIGNMENT,
            self::TYPE_TERMINAL_ALLOCATION,
            self::TYPE_CONTAINER_MOVEMENT,
            self::TYPE_YARD_ASSIGNMENT,
            self::TYPE_PORT_TERMINAL_ASSIGNMENT,
            self::TYPE_MANIFEST_UPLOADED,
            self::TYPE_MANIFEST_CONSIGNEE_DECLARED,
            self::TYPE_MANIFEST_STATE_TRANSITION,
            self::TYPE_MANIFEST_PAYMENT_SUBMITTED,
            self::TYPE_MANIFEST_PAYMENT_VALIDATED,
            self::TYPE_MANIFEST_PAYMENT_REJECTED,
            self::TYPE_MANIFEST_NOA_GENERATED,
            self::TYPE_MANIFEST_BILLING_GENERATED,
            self::TYPE_MANIFEST_EDO_GENERATED,
            self::TYPE_MANIFEST_BL_UPLOADED,
            self::TYPE_MANIFEST_DOCUMENT_DOWNLOADED,
        ];

        return in_array($this->activityType, $businessTypes);
    }

    /**
     * Gets all available activity types
     */
    public static function getAllActivityTypes(): array
    {
        return [
            self::TYPE_LOGIN,
            self::TYPE_LOGOUT,
            self::TYPE_FAILED_LOGIN,
            self::TYPE_PASSWORD_CHANGE,
            self::TYPE_SESSION_TIMEOUT,
            self::TYPE_CREATE,
            self::TYPE_UPDATE,
            self::TYPE_DELETE,
            self::TYPE_VIEW,
            self::TYPE_SEARCH,
            self::TYPE_EXPORT,
            self::TYPE_IMPORT,
            self::TYPE_REPORT_GENERATION,
            self::TYPE_USER_CREATION,
            self::TYPE_USER_SUSPENSION,
            self::TYPE_USER_ACTIVATION,
            self::TYPE_ROLE_CHANGE,
            self::TYPE_HIERARCHY_CHANGE,
            self::TYPE_SHIPPING_LINE_CREATION,
            self::TYPE_SHIPPING_LINE_UPDATE,
            self::TYPE_SHIPPING_LINE_ACTIVATION,
            self::TYPE_SHIPPING_LINE_DEACTIVATION,
            self::TYPE_ADMIN_ASSIGNMENT,
            self::TYPE_CONFIG_CHANGE,
            self::TYPE_PERMISSION_CHANGE,
            self::TYPE_BRANDING_CHANGE,
            self::TYPE_SYSTEM_MAINTENANCE,
            self::TYPE_DATABASE_MIGRATION,
            self::TYPE_BACKUP_OPERATION,
            self::TYPE_ACCESS_DENIED,
            self::TYPE_SUSPICIOUS_ACTIVITY,
            self::TYPE_PRIVILEGE_ESCALATION_ATTEMPT,
            self::TYPE_PRE_ADVICE_CREATION,
            self::TYPE_PAYMENT_PROCESSING,
            self::TYPE_DOCUMENT_UPLOAD,
            self::TYPE_STATUS_CHANGE,
            self::TYPE_CONTAINER_ASSIGNMENT,
            self::TYPE_TERMINAL_ALLOCATION,
            self::TYPE_CONTAINER_MOVEMENT,
            self::TYPE_YARD_ASSIGNMENT,
            self::TYPE_PORT_TERMINAL_ASSIGNMENT,
            self::TYPE_CONTAINER_TYPE_CREATED,
            self::TYPE_CONTAINER_TYPE_UPDATED,
            self::TYPE_CONTAINER_TYPE_DELETED,
            self::TYPE_CONTAINER_SIZE_CREATED,
            self::TYPE_CONTAINER_SIZE_UPDATED,
            self::TYPE_CONTAINER_SIZE_DELETED,
            self::TYPE_MANIFEST_UPLOADED,
            self::TYPE_MANIFEST_CONSIGNEE_DECLARED,
            self::TYPE_MANIFEST_STATE_TRANSITION,
            self::TYPE_MANIFEST_PAYMENT_SUBMITTED,
            self::TYPE_MANIFEST_PAYMENT_VALIDATED,
            self::TYPE_MANIFEST_PAYMENT_REJECTED,
            self::TYPE_MANIFEST_NOA_GENERATED,
            self::TYPE_MANIFEST_BILLING_GENERATED,
            self::TYPE_MANIFEST_EDO_GENERATED,
            self::TYPE_MANIFEST_BL_UPLOADED,
            self::TYPE_MANIFEST_DOCUMENT_DOWNLOADED,
        ];
    }

    /**
     * Validates the activity log data
     */
    public function validate(): array
    {
        $errors = [];
        
        if (!isset($this->activityType) || empty($this->activityType)) {
            $errors[] = 'Activity type is required';
        } elseif (!in_array($this->activityType, self::getAllActivityTypes())) {
            $errors[] = 'Invalid activity type';
        }
        
        if (!isset($this->ipAddress) || empty($this->ipAddress)) {
            $errors[] = 'IP address is required';
        }
        
        return $errors;
    }

    public function __toString(): string
    {
        return sprintf(
            '[%s] %s by %s at %s',
            $this->activityType,
            $this->getActivityDescription(),
            $this->user->getEmail(),
            $this->createdAt->format('Y-m-d H:i:s')
        );
    }
}