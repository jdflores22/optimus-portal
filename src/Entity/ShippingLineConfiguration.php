<?php

namespace App\Entity;

use App\Entity\Enum\UserRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'shipping_line_configurations')]
#[ORM\Index(columns: ['shipping_line_id'], name: 'idx_shipping_line_configurations_shipping_line')]
#[ORM\Index(columns: ['config_key'], name: 'idx_shipping_line_configurations_key')]
#[ORM\Index(columns: ['created_at'], name: 'idx_shipping_line_configurations_created_at')]
class ShippingLineConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShippingLine $shippingLine;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Configuration key is required')]
    #[Assert\Length(max: 255, maxMessage: 'Configuration key cannot be longer than {{ limit }} characters')]
    private string $configKey;

    #[ORM\Column(type: 'json')]
    private array $configValue = [];

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

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

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): self
    {
        $this->configKey = $configKey;
        $this->updateTimestamp();
        return $this;
    }

    public function getConfigValue(): array
    {
        return $this->configValue;
    }

    public function setConfigValue(array $configValue): self
    {
        $this->configValue = $configValue;
        $this->updateTimestamp();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
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
     * Gets a specific value from the configuration
     */
    public function getValue(string $key, $default = null)
    {
        return $this->configValue[$key] ?? $default;
    }

    /**
     * Sets a specific value in the configuration
     */
    public function setValue(string $key, $value): self
    {
        $this->configValue[$key] = $value;
        $this->updateTimestamp();
        return $this;
    }

    /**
     * Validates if the configuration is properly structured
     */
    public function validate(): array
    {
        $errors = [];
        
        if (empty($this->configKey)) {
            $errors[] = 'Configuration key is required';
        }
        
        if (!is_array($this->configValue)) {
            $errors[] = 'Configuration value must be an array';
        }
        
        return $errors;
    }

    public function __toString(): string
    {
        return $this->configKey . ' (' . $this->shippingLine->getBrandName() . ')';
    }
}