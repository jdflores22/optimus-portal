<?php

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Repository\ShippingLineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ShippingLineRepository::class)]
#[ORM\Table(name: 'shipping_lines')]
#[ORM\Index(columns: ['brand_name'], name: 'idx_shipping_lines_brand_name')]
#[ORM\Index(columns: ['is_active'], name: 'idx_shipping_lines_active')]
#[ORM\Index(columns: ['created_at'], name: 'idx_shipping_lines_created_at')]
#[ORM\Index(columns: ['logo_path'], name: 'idx_shipping_lines_logo')]
#[ORM\Index(columns: ['brand_color'], name: 'idx_shipping_lines_brand_color')]
#[ORM\UniqueConstraint(name: 'UNIQ_shipping_lines_brand_name', columns: ['brand_name'])]
class ShippingLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Brand name is required')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Brand name must be at least {{ limit }} characters long',
        maxMessage: 'Brand name cannot be longer than {{ limit }} characters'
    )]
    private string $brandName;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    #[Assert\Length(
        max: 500,
        maxMessage: 'Logo path cannot be longer than {{ limit }} characters'
    )]
    private ?string $logoPath = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    #[Assert\Regex(
        pattern: '/^#[0-9A-Fa-f]{6}$/',
        message: 'Brand color must be a valid hex color code (e.g., #0066CC)'
    )]
    private ?string $brandColor = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $portalConfig = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\OneToMany(mappedBy: 'managedShippingLine', targetEntity: User::class)]
    private Collection $shippingLineAdmins;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: ActivityLog::class)]
    private Collection $activityLogs;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: Manifest::class)]
    private Collection $manifests;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: Payment::class)]
    private Collection $payments;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: EDOPayment::class)]
    private Collection $edoPayments;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: ElectronicDeliveryOrder::class)]
    private Collection $electronicDeliveryOrders;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: AccreditationSubmission::class)]
    private Collection $accreditationSubmissions;

    #[ORM\OneToMany(mappedBy: 'shippingLine', targetEntity: Notification::class)]
    private Collection $notifications;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->shippingLineAdmins = new ArrayCollection();
        $this->activityLogs = new ArrayCollection();
        $this->manifests = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->edoPayments = new ArrayCollection();
        $this->electronicDeliveryOrders = new ArrayCollection();
        $this->accreditationSubmissions = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->portalConfig = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrandName(): string
    {
        return $this->brandName;
    }

    public function setBrandName(string $brandName): self
    {
        $this->brandName = $brandName;
        $this->updateTimestamp();
        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): self
    {
        $this->logoPath = $logoPath;
        $this->updateTimestamp();
        return $this;
    }

    public function getBrandColor(): ?string
    {
        return $this->brandColor;
    }

    public function setBrandColor(?string $brandColor): self
    {
        $this->brandColor = $brandColor;
        $this->updateTimestamp();
        return $this;
    }

    public function getLogoUrl(): ?string
    {
        if ($this->logoPath === null) {
            return null;
        }
        
        // Return the public URL for the logo
        return '/uploads/shipping-lines/' . basename($this->logoPath);
    }

    public function getPortalConfig(): ?array
    {
        return $this->portalConfig;
    }

    public function setPortalConfig(?array $portalConfig): self
    {
        $this->portalConfig = $portalConfig ?? [];
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
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

    /**
     * @return Collection<int, User>
     */
    public function getShippingLineAdmins(): Collection
    {
        return $this->shippingLineAdmins;
    }

    public function addShippingLineAdmin(User $admin): self
    {
        if (!$this->shippingLineAdmins->contains($admin)) {
            $this->shippingLineAdmins->add($admin);
            $admin->setManagedShippingLine($this);
        }
        return $this;
    }

    public function removeShippingLineAdmin(User $admin): self
    {
        if ($this->shippingLineAdmins->removeElement($admin)) {
            if ($admin->getManagedShippingLine() === $this) {
                $admin->setManagedShippingLine(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function getActivityLogs(): Collection
    {
        return $this->activityLogs;
    }

    public function addActivityLog(ActivityLog $activityLog): self
    {
        if (!$this->activityLogs->contains($activityLog)) {
            $this->activityLogs->add($activityLog);
            $activityLog->setShippingLine($this);
        }
        return $this;
    }

    public function removeActivityLog(ActivityLog $activityLog): self
    {
        if ($this->activityLogs->removeElement($activityLog)) {
            if ($activityLog->getShippingLine() === $this) {
                $activityLog->setShippingLine(null);
            }
        }
        return $this;
    }

    // Business Logic Methods

    /**
     * Validates if a user can be assigned as admin for this shipping line
     */
    public function canAssignAdmin(User $user): bool
    {
        return $user->getRole() === UserRole::SHIPPING_LINES_ADMIN 
            && $user->getManagedShippingLine() === null;
    }

    /**
     * Gets all users within this shipping line's scope
     */
    public function getScopedUsers(): array
    {
        $users = [];
        
        // Add all shipping line admins
        foreach ($this->shippingLineAdmins as $admin) {
            $users[] = $admin;
            
            // Add all subordinate users of each admin
            $users = array_merge($users, $admin->getSubordinateUsers()->toArray());
        }
        
        return $users;
    }

    /**
     * Checks if this shipping line has any active admins
     */
    public function hasActiveAdmins(): bool
    {
        foreach ($this->shippingLineAdmins as $admin) {
            if ($admin->isActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the portal configuration value for a specific key
     */
    public function getPortalConfigValue(string $key, $default = null)
    {
        return $this->portalConfig[$key] ?? $default;
    }

    /**
     * Sets a portal configuration value
     */
    public function setPortalConfigValue(string $key, $value): self
    {
        if ($this->portalConfig === null) {
            $this->portalConfig = [];
        }
        $this->portalConfig[$key] = $value;
        $this->updateTimestamp();
        return $this;
    }

    /**
     * Validates the shipping line data
     */
    public function validate(): array
    {
        $errors = [];
        
        if (!isset($this->brandName) || empty($this->brandName)) {
            $errors[] = 'Brand name is required';
        } elseif (strlen($this->brandName) < 2) {
            $errors[] = 'Brand name must be at least 2 characters long';
        } elseif (strlen($this->brandName) > 255) {
            $errors[] = 'Brand name cannot be longer than 255 characters';
        }
        
        return $errors;
    }

    /**
     * Deactivates the shipping line and handles dependent relationships
     */
    public function deactivate(): self
    {
        $this->isActive = false;
        $this->updateTimestamp();
        return $this;
    }

    /**
     * Activates the shipping line
     */
    public function activate(): self
    {
        $this->isActive = true;
        $this->updateTimestamp();
        return $this;
    }

    /**
     * @return Collection<int, Manifest>
     */
    public function getManifests(): Collection
    {
        return $this->manifests;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * @return Collection<int, EDOPayment>
     */
    public function getEdoPayments(): Collection
    {
        return $this->edoPayments;
    }

    /**
     * @return Collection<int, ElectronicDeliveryOrder>
     */
    public function getElectronicDeliveryOrders(): Collection
    {
        return $this->electronicDeliveryOrders;
    }

    /**
     * @return Collection<int, AccreditationSubmission>
     */
    public function getAccreditationSubmissions(): Collection
    {
        return $this->accreditationSubmissions;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    private function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function __toString(): string
    {
        return $this->brandName;
    }
}