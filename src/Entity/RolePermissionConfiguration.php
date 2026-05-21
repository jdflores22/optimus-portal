<?php

namespace App\Entity;

use App\Entity\Enum\UserRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'role_permission_configurations')]
#[ORM\Index(columns: ['shipping_line_id'], name: 'idx_role_permissions_shipping_line')]
#[ORM\Index(columns: ['role'], name: 'idx_role_permissions_role')]
#[ORM\Index(columns: ['created_at'], name: 'idx_role_permissions_created_at')]
#[ORM\UniqueConstraint(name: 'UNIQ_role_permissions_shipping_line_role', columns: ['shipping_line_id', 'role'])]
class RolePermissionConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShippingLine $shippingLine;

    #[ORM\Column(type: 'string', enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $restrictions = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $inheritFromParent = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->permissions = [];
        $this->restrictions = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShippingLine(): ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): self
    {
        $this->role = $role;
        $this->updateTimestamp();
        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;
        $this->updateTimestamp();
        return $this;
    }

    public function getRestrictions(): ?array
    {
        return $this->restrictions;
    }

    public function setRestrictions(?array $restrictions): self
    {
        $this->restrictions = $restrictions;
        $this->updateTimestamp();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updateTimestamp();
        return $this;
    }

    public function isInheritFromParent(): bool
    {
        return $this->inheritFromParent;
    }

    public function setInheritFromParent(bool $inheritFromParent): self
    {
        $this->inheritFromParent = $inheritFromParent;
        $this->updateTimestamp();
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;
        $this->updateTimestamp();
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    private function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // Business Logic Methods

    /**
     * Checks if a specific permission is granted
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Adds a permission to the role
     */
    public function addPermission(string $permission): self
    {
        if (!$this->hasPermission($permission)) {
            $this->permissions[] = $permission;
            $this->updateTimestamp();
        }
        return $this;
    }

    /**
     * Removes a permission from the role
     */
    public function removePermission(string $permission): self
    {
        $key = array_search($permission, $this->permissions, true);
        if ($key !== false) {
            unset($this->permissions[$key]);
            $this->permissions = array_values($this->permissions); // Re-index array
            $this->updateTimestamp();
        }
        return $this;
    }

    /**
     * Checks if a specific restriction applies
     */
    public function hasRestriction(string $restriction): bool
    {
        return isset($this->restrictions[$restriction]) && $this->restrictions[$restriction] === true;
    }

    /**
     * Sets a restriction value
     */
    public function setRestriction(string $restriction, $value): self
    {
        if ($this->restrictions === null) {
            $this->restrictions = [];
        }
        $this->restrictions[$restriction] = $value;
        $this->updateTimestamp();
        return $this;
    }

    /**
     * Gets the effective permissions including inheritance
     */
    public function getEffectivePermissions(): array
    {
        $effectivePermissions = $this->permissions;
        
        if ($this->inheritFromParent) {
            // Add parent role permissions based on hierarchy
            $parentPermissions = $this->getParentRolePermissions();
            $effectivePermissions = array_unique(array_merge($effectivePermissions, $parentPermissions));
        }
        
        return $effectivePermissions;
    }

    /**
     * Gets parent role permissions based on role hierarchy
     */
    private function getParentRolePermissions(): array
    {
        // Define role hierarchy - child roles can inherit from parent roles
        $roleHierarchy = [
            UserRole::SL_STAFF => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::EVALUATOR => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::ACCOUNTING => UserRole::SHIPPING_LINES_ADMIN,
            UserRole::TERMINAL_TEAM => UserRole::SHIPPING_LINES_ADMIN,
        ];
        
        if (!isset($roleHierarchy[$this->role])) {
            return [];
        }
        
        // This would need to be implemented with a service to fetch parent permissions
        // For now, return empty array - this should be handled by the service layer
        return [];
    }

    /**
     * Validates the permission configuration
     */
    public function validate(): array
    {
        $errors = [];
        
        if (!is_array($this->permissions)) {
            $errors[] = 'Permissions must be an array';
        }
        
        if ($this->restrictions !== null && !is_array($this->restrictions)) {
            $errors[] = 'Restrictions must be an array or null';
        }
        
        return $errors;
    }

    public function __toString(): string
    {
        return $this->role->value . ' (' . $this->shippingLine->getBrandName() . ')';
    }
}