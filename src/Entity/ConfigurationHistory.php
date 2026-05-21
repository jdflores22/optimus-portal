<?php

namespace App\Entity;

use App\Repository\ConfigurationHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration History Entity
 * 
 * Tracks version history of configuration changes
 * Requirements: 7.2
 */
#[ORM\Entity(repositoryClass: ConfigurationHistoryRepository::class)]
#[ORM\Table(name: 'configuration_history')]
#[ORM\Index(columns: ['config_key'], name: 'idx_history_config_key')]
#[ORM\Index(columns: ['changed_at'], name: 'idx_history_changed_at')]
class ConfigurationHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $configKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $oldValue;

    #[ORM\Column(type: Types::TEXT)]
    private string $newValue;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $changedBy;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $changedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changeReason = null;

    public function __construct()
    {
        $this->changedAt = new \DateTime();
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

    public function getOldValue(): string
    {
        return $this->oldValue;
    }

    public function setOldValue(string $oldValue): self
    {
        $this->oldValue = $oldValue;
        return $this;
    }

    public function getNewValue(): string
    {
        return $this->newValue;
    }

    public function setNewValue(string $newValue): self
    {
        $this->newValue = $newValue;
        return $this;
    }

    public function getChangedBy(): User
    {
        return $this->changedBy;
    }

    public function setChangedBy(User $changedBy): self
    {
        $this->changedBy = $changedBy;
        return $this;
    }

    public function getChangedAt(): \DateTimeInterface
    {
        return $this->changedAt;
    }

    public function getChangeReason(): ?string
    {
        return $this->changeReason;
    }

    public function setChangeReason(?string $changeReason): self
    {
        $this->changeReason = $changeReason;
        return $this;
    }
}
