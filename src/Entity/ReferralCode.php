<?php

namespace App\Entity;

use App\Repository\ReferralCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReferralCodeRepository::class)]
#[ORM\Table(name: 'referral_codes')]
#[ORM\Index(name: 'idx_referral_code', columns: ['code'])]
#[ORM\Index(name: 'idx_referral_consignee', columns: ['consignee_id'])]
#[ORM\Index(name: 'idx_referral_active', columns: ['is_active'])]
class ReferralCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'consignee_id', nullable: false, onDelete: 'CASCADE')]
    private User $consignee;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $code;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $maxUses = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $currentUses = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deactivatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConsignee(): User
    {
        return $this->consignee;
    }

    public function setConsignee(User $consignee): self
    {
        $this->consignee = $consignee;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
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

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;
        return $this;
    }

    public function getCurrentUses(): int
    {
        return $this->currentUses;
    }

    public function setCurrentUses(int $currentUses): self
    {
        $this->currentUses = $currentUses;
        return $this;
    }

    public function incrementUses(): self
    {
        $this->currentUses++;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
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

    public function getDeactivatedAt(): ?\DateTimeInterface
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?\DateTimeInterface $deactivatedAt): self
    {
        $this->deactivatedAt = $deactivatedAt;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }

    public function hasReachedMaxUses(): bool
    {
        return $this->maxUses !== null && $this->currentUses >= $this->maxUses;
    }

    public function isValid(): bool
    {
        return $this->isActive && !$this->isExpired() && !$this->hasReachedMaxUses();
    }
}
