<?php

namespace App\Entity;

use App\Repository\PaymentFeeConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentFeeConfigurationRepository::class)]
#[ORM\Table(name: 'payment_fee_configurations')]
#[ORM\Index(name: 'idx_fee_type', columns: ['fee_type'])]
#[ORM\Index(name: 'idx_configured_at', columns: ['configured_at'])]
#[ORM\Index(name: 'idx_is_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_fee_type_active', columns: ['fee_type', 'is_active'])]
class PaymentFeeConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $feeType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $amount;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $configuredBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $configuredAt;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $previousAmount = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $qrCodePath = null;

    public function __construct()
    {
        $this->configuredAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFeeType(): string
    {
        return $this->feeType;
    }

    public function setFeeType(string $feeType): self
    {
        $this->feeType = $feeType;
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getConfiguredBy(): User
    {
        return $this->configuredBy;
    }

    public function setConfiguredBy(User $configuredBy): self
    {
        $this->configuredBy = $configuredBy;
        return $this;
    }

    public function getConfiguredAt(): \DateTimeInterface
    {
        return $this->configuredAt;
    }

    public function setConfiguredAt(\DateTimeInterface $configuredAt): self
    {
        $this->configuredAt = $configuredAt;
        return $this;
    }

    public function getPreviousAmount(): ?float
    {
        return $this->previousAmount;
    }

    public function setPreviousAmount(?float $previousAmount): self
    {
        $this->previousAmount = $previousAmount;
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

    public function getQrCodePath(): ?string
    {
        return $this->qrCodePath;
    }

    public function setQrCodePath(?string $qrCodePath): self
    {
        $this->qrCodePath = $qrCodePath;
        return $this;
    }
}
