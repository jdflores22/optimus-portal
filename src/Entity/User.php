<?php

namespace App\Entity;

use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'consignee' => Consignee::class,
    'broker' => Broker::class,
    'staff' => StaffUser::class,
    'terminal_team' => TerminalTeamUser::class,
    'trucker' => Trucker::class
])]
#[ORM\Index(columns: ['email'], name: 'idx_user_email')]
#[ORM\Index(columns: ['shipping_line_admin_id'], name: 'idx_users_shipping_line_admin')]
#[ORM\Index(columns: ['managed_shipping_line_id'], name: 'idx_users_managed_shipping_line')]
#[ORM\Index(columns: ['role', 'shipping_line_admin_id', 'managed_shipping_line_id'], name: 'idx_users_hierarchy_lookup')]
abstract class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected string $email;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $passwordHash;

    #[ORM\Column(type: 'string', enumType: UserRole::class)]
    protected UserRole $role;

    #[ORM\Column(type: 'string', enumType: AccountStatus::class)]
    protected AccountStatus $status;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $isActive = true;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $deactivatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deactivated_by_id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $deactivatedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $deactivationReason = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $suspensionAttachments = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    protected int $failedLoginAttempts = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $lockedUntil = null;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $emailVerificationToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $emailVerificationTokenExpiresAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $emailVerifiedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $welcomeModalDismissedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $onboardingGuideCompletedSteps = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $profilePhoto = null;

    #[ORM\Column(type: 'string', length: 6, nullable: true)]
    protected ?string $passwordResetOtp = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $passwordResetOtpExpiresAt = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'subordinateUsers')]
    #[ORM\JoinColumn(name: 'shipping_line_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?User $shippingLineAdmin = null;

    #[ORM\OneToMany(mappedBy: 'shippingLineAdmin', targetEntity: User::class)]
    protected Collection $subordinateUsers;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'shippingLineAdmins')]
    #[ORM\JoinColumn(name: 'managed_shipping_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?ShippingLine $managedShippingLine = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = AccountStatus::EMAIL_UNVERIFIED;
        $this->failedLoginAttempts = 0;
        $this->subordinateUsers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): self
    {
        $this->passwordHash = $passwordHash;
        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function setStatus(AccountStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function setFailedLoginAttempts(int $failedLoginAttempts): self
    {
        $this->failedLoginAttempts = $failedLoginAttempts;
        return $this;
    }

    public function incrementFailedLoginAttempts(): self
    {
        $this->failedLoginAttempts++;
        return $this;
    }

    public function resetFailedLoginAttempts(): self
    {
        $this->failedLoginAttempts = 0;
        return $this;
    }

    public function getLockedUntil(): ?\DateTimeInterface
    {
        return $this->lockedUntil;
    }

    public function setLockedUntil(?\DateTimeInterface $lockedUntil): self
    {
        $this->lockedUntil = $lockedUntil;
        return $this;
    }

    public function isLocked(): bool
    {
        if ($this->status === AccountStatus::LOCKED) {
            return true;
        }

        if ($this->lockedUntil !== null && $this->lockedUntil > new \DateTime()) {
            return true;
        }

        return false;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $emailVerificationToken): self
    {
        $this->emailVerificationToken = $emailVerificationToken;
        return $this;
    }

    public function getEmailVerificationTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->emailVerificationTokenExpiresAt;
    }

    public function setEmailVerificationTokenExpiresAt(?\DateTimeInterface $emailVerificationTokenExpiresAt): self
    {
        $this->emailVerificationTokenExpiresAt = $emailVerificationTokenExpiresAt;
        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeInterface
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeInterface $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getWelcomeModalDismissedAt(): ?\DateTimeInterface
    {
        return $this->welcomeModalDismissedAt;
    }

    public function setWelcomeModalDismissedAt(?\DateTimeInterface $welcomeModalDismissedAt): self
    {
        $this->welcomeModalDismissedAt = $welcomeModalDismissedAt;
        return $this;
    }

    public function hasSeenWelcomeModal(): bool
    {
        return $this->welcomeModalDismissedAt !== null;
    }

    /** @return string[] */
    public function getOnboardingGuideCompletedSteps(): array
    {
        return $this->onboardingGuideCompletedSteps ?? [];
    }

    public function hasCompletedGuideStep(string $step): bool
    {
        return in_array($step, $this->getOnboardingGuideCompletedSteps(), true);
    }

    public function completeGuideStep(string $step): self
    {
        $steps = $this->getOnboardingGuideCompletedSteps();
        if (!in_array($step, $steps, true)) {
            $steps[] = $step;
            $this->onboardingGuideCompletedSteps = $steps;
        }

        return $this;
    }

    public function isEmailVerificationTokenValid(): bool
    {
        return $this->emailVerificationToken !== null 
            && $this->emailVerificationTokenExpiresAt !== null 
            && $this->emailVerificationTokenExpiresAt > new \DateTime();
    }

    public function getProfilePhoto(): ?string
    {
        return $this->profilePhoto;
    }

    public function setProfilePhoto(?string $profilePhoto): self
    {
        $this->profilePhoto = $profilePhoto;
        return $this;
    }

    public function getPasswordResetOtp(): ?string
    {
        return $this->passwordResetOtp;
    }

    public function setPasswordResetOtp(?string $passwordResetOtp): self
    {
        $this->passwordResetOtp = $passwordResetOtp;
        return $this;
    }

    public function getPasswordResetOtpExpiresAt(): ?\DateTimeInterface
    {
        return $this->passwordResetOtpExpiresAt;
    }

    public function setPasswordResetOtpExpiresAt(?\DateTimeInterface $passwordResetOtpExpiresAt): self
    {
        $this->passwordResetOtpExpiresAt = $passwordResetOtpExpiresAt;
        return $this;
    }

    public function isPasswordResetOtpValid(): bool
    {
        return $this->passwordResetOtp !== null 
            && $this->passwordResetOtpExpiresAt !== null 
            && $this->passwordResetOtpExpiresAt > new \DateTime();
    }

    // UserInterface implementation
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_' . $this->role->value];
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase
    }

    // PasswordAuthenticatedUserInterface implementation
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    // Hierarchy Management Methods

    public function getShippingLineAdmin(): ?User
    {
        return $this->shippingLineAdmin;
    }

    public function setShippingLineAdmin(?User $shippingLineAdmin): self
    {
        $this->shippingLineAdmin = $shippingLineAdmin;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getSubordinateUsers(): Collection
    {
        return $this->subordinateUsers;
    }

    public function addSubordinateUser(User $user): self
    {
        if (!$this->subordinateUsers->contains($user)) {
            $this->subordinateUsers->add($user);
            $user->setShippingLineAdmin($this);
        }
        return $this;
    }

    public function removeSubordinateUser(User $user): self
    {
        if ($this->subordinateUsers->removeElement($user)) {
            if ($user->getShippingLineAdmin() === $this) {
                $user->setShippingLineAdmin(null);
            }
        }
        return $this;
    }

    public function getManagedShippingLine(): ?ShippingLine
    {
        return $this->managedShippingLine;
    }

    public function setManagedShippingLine(?ShippingLine $managedShippingLine): self
    {
        $this->managedShippingLine = $managedShippingLine;
        return $this;
    }

    // Business Logic Methods

    /**
     * Gets the shipping line scope for this user
     */
    public function getShippingLineScope(): ?ShippingLine
    {
        // SHIPPING_LINES_ADMIN manages a specific shipping line
        if ($this->role === UserRole::SHIPPING_LINES_ADMIN) {
            return $this->managedShippingLine;
        }
        
        // Subordinate users inherit scope from their admin
        if ($this->shippingLineAdmin !== null) {
            return $this->shippingLineAdmin->getManagedShippingLine();
        }
        
        // SYSTEM_ADMIN and independent roles (CONSIGNEE, BROKER, TRUCKER) have no specific scope
        return null;
    }

    /**
     * Checks if this user can manage another user
     */
    public function canManageUser(User $user): bool
    {
        // SYSTEM_ADMIN can manage anyone
        if ($this->role === UserRole::SYSTEM_ADMIN) {
            return true;
        }
        
        // SHIPPING_LINES_ADMIN can manage their subordinates
        if ($this->role === UserRole::SHIPPING_LINES_ADMIN) {
            return $user->getShippingLineAdmin() === $this;
        }
        
        return false;
    }

    /**
     * Gets the hierarchy level (0 = SYSTEM_ADMIN, 1 = SHIPPING_LINES_ADMIN, 2 = subordinates)
     */
    public function getHierarchyLevel(): int
    {
        switch ($this->role) {
            case UserRole::SYSTEM_ADMIN:
                return 0;
            case UserRole::SHIPPING_LINES_ADMIN:
                return 1;
            case UserRole::SL_STAFF:
            case UserRole::EVALUATOR:
            case UserRole::ACCOUNTING:
            case UserRole::TERMINAL_TEAM:
                return 2;
            default:
                return 3; // Independent roles
        }
    }

    /**
     * Checks if this user requires a shipping line admin link
     */
    public function requiresShippingLineAdmin(): bool
    {
        return in_array($this->role, [
            UserRole::SL_STAFF,
            UserRole::EVALUATOR,
            UserRole::ACCOUNTING,
            UserRole::TERMINAL_TEAM,
        ]);
    }

    /**
     * Checks if this user requires a managed shipping line
     */
    public function requiresManagedShippingLine(): bool
    {
        return $this->role === UserRole::SHIPPING_LINES_ADMIN;
    }

    /**
     * Validates the user hierarchy relationships
     */
    public function validateHierarchy(): array
    {
        $errors = [];
        
        // Check if hierarchical roles have required parent
        if ($this->requiresShippingLineAdmin() && $this->shippingLineAdmin === null) {
            $errors[] = sprintf('%s role requires a shipping line admin', $this->role->value);
        }
        
        // Check if SHIPPING_LINES_ADMIN has managed shipping line
        if ($this->requiresManagedShippingLine() && $this->managedShippingLine === null) {
            $errors[] = 'SHIPPING_LINES_ADMIN role requires a managed shipping line';
        }
        
        // Check if non-hierarchical roles don't have admin
        if (!$this->requiresShippingLineAdmin() && 
            $this->role !== UserRole::SHIPPING_LINES_ADMIN && 
            $this->shippingLineAdmin !== null) {
            $errors[] = sprintf('%s role should not have a shipping line admin', $this->role->value);
        }
        
        // Validate admin's role
        if ($this->shippingLineAdmin !== null && 
            $this->shippingLineAdmin->getRole() !== UserRole::SHIPPING_LINES_ADMIN) {
            $errors[] = 'Shipping line admin must have SHIPPING_LINES_ADMIN role';
        }
        
        return $errors;
    }

    /**
     * Checks if this user is active (not locked, suspended, etc.)
     */
    public function isActive(): bool
    {
        return $this->isActive && $this->status === AccountStatus::APPROVED && !$this->isLocked();
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeInterface
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?\DateTimeInterface $deactivatedAt): self
    {
        $this->deactivatedAt = $deactivatedAt;
        return $this;
    }

    public function getDeactivatedBy(): ?User
    {
        return $this->deactivatedBy;
    }

    public function setDeactivatedBy(?User $deactivatedBy): self
    {
        $this->deactivatedBy = $deactivatedBy;
        return $this;
    }

    public function getDeactivationReason(): ?string
    {
        return $this->deactivationReason;
    }

    public function setDeactivationReason(?string $deactivationReason): self
    {
        $this->deactivationReason = $deactivationReason;
        return $this;
    }

    public function getSuspensionAttachments(): ?array
    {
        return $this->suspensionAttachments;
    }

    public function setSuspensionAttachments(?array $suspensionAttachments): self
    {
        $this->suspensionAttachments = $suspensionAttachments;
        return $this;
    }

    public function addSuspensionAttachment(string $filePath): self
    {
        if ($this->suspensionAttachments === null) {
            $this->suspensionAttachments = [];
        }
        $this->suspensionAttachments[] = $filePath;
        return $this;
    }

    public function deactivate(User $deactivatedBy, string $reason): self
    {
        $this->isActive = false;
        $this->deactivatedAt = new \DateTime();
        $this->deactivatedBy = $deactivatedBy;
        $this->deactivationReason = $reason;
        return $this;
    }

    public function reactivate(): self
    {
        $this->isActive = true;
        $this->deactivatedAt = null;
        $this->deactivatedBy = null;
        $this->deactivationReason = null;
        return $this;
    }

    /**
     * Gets all users in the same shipping line scope
     */
    public function getScopedUsers(): array
    {
        $shippingLine = $this->getShippingLineScope();
        
        if ($shippingLine === null) {
            return []; // No scope restriction for SYSTEM_ADMIN and independent roles
        }
        
        return $shippingLine->getScopedUsers();
    }

    /**
     * Checks if this user has permission for a specific action within their scope
     */
    public function hasPermission(string $action, ?object $entity = null): bool
    {
        // SYSTEM_ADMIN has all permissions
        if ($this->role === UserRole::SYSTEM_ADMIN) {
            return true;
        }
        
        // Check shipping line scope if entity is provided
        if ($entity !== null && method_exists($entity, 'getShippingLineScope')) {
            $entityScope = $entity->getShippingLineScope();
            $userScope = $this->getShippingLineScope();
            
            if ($entityScope !== $userScope) {
                return false;
            }
        }
        
        // Role-specific permissions can be implemented here
        // For now, return true for basic access within scope
        return true;
    }
}
