<?php

namespace App\Entity;

use App\Repository\SystemConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * System Configuration Entity
 * 
 * Stores system-wide configuration settings with version history
 * Requirements: 7.2
 */
#[ORM\Entity(repositoryClass: SystemConfigurationRepository::class)]
#[ORM\Table(name: 'system_configurations')]
#[ORM\Index(columns: ['config_key'], name: 'idx_config_key')]
#[ORM\Index(columns: ['is_active'], name: 'idx_is_active')]
class SystemConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    private string $configKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $configValue;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $valueType; // 'string', 'integer', 'float', 'boolean', 'json'

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
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

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): self
    {
        $this->configKey = $configKey;
        return $this;
    }

    public function getConfigValue(): string
    {
        return $this->configValue;
    }

    public function setConfigValue(string $configValue): self
    {
        $this->configValue = $configValue;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getValueType(): string
    {
        return $this->valueType;
    }

    public function setValueType(string $valueType): self
    {
        $this->valueType = $valueType;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;
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

    /**
     * Get the typed value based on valueType
     */
    public function getTypedValue(): mixed
    {
        return match ($this->valueType) {
            'integer' => (int) $this->configValue,
            'float' => (float) $this->configValue,
            'boolean' => filter_var($this->configValue, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->configValue, true),
            default => $this->configValue,
        };
    }

    /**
     * Set value with automatic type conversion
     */
    public function setTypedValue(mixed $value, string $type): self
    {
        $this->valueType = $type;
        $this->configValue = match ($type) {
            'json' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
        $this->updatedAt = new \DateTime();
        return $this;
    }
}
