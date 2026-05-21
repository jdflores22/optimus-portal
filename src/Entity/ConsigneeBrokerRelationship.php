<?php

namespace App\Entity;

use App\Repository\ConsigneeBrokerRelationshipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConsigneeBrokerRelationshipRepository::class)]
#[ORM\Table(name: 'consignee_broker_relationships')]
#[ORM\UniqueConstraint(name: 'uniq_relationship', columns: ['consignee_id', 'broker_id'])]
#[ORM\Index(name: 'idx_cbr_consignee', columns: ['consignee_id'])]
#[ORM\Index(name: 'idx_cbr_broker', columns: ['broker_id'])]
#[ORM\Index(name: 'idx_cbr_status', columns: ['status'])]
class ConsigneeBrokerRelationship
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_TERMINATED = 'terminated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'consignee_id', nullable: false, onDelete: 'CASCADE')]
    private User $consignee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'broker_id', nullable: false, onDelete: 'CASCADE')]
    private User $broker;

    #[ORM\ManyToOne(targetEntity: ReferralCode::class)]
    #[ORM\JoinColumn(name: 'referral_code_id', nullable: false, onDelete: 'RESTRICT')]
    private ReferralCode $referralCode;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_ACTIVE])]
    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_TERMINATED])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $suspendedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'suspended_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $suspendedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $suspensionReason = null;

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

    public function getBroker(): User
    {
        return $this->broker;
    }

    public function setBroker(User $broker): self
    {
        $this->broker = $broker;
        return $this;
    }

    public function getReferralCode(): ReferralCode
    {
        return $this->referralCode;
    }

    public function setReferralCode(ReferralCode $referralCode): self
    {
        $this->referralCode = $referralCode;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getSuspendedAt(): ?\DateTimeInterface
    {
        return $this->suspendedAt;
    }

    public function setSuspendedAt(?\DateTimeInterface $suspendedAt): self
    {
        $this->suspendedAt = $suspendedAt;
        return $this;
    }

    public function getSuspendedBy(): ?User
    {
        return $this->suspendedBy;
    }

    public function setSuspendedBy(?User $suspendedBy): self
    {
        $this->suspendedBy = $suspendedBy;
        return $this;
    }

    public function getSuspensionReason(): ?string
    {
        return $this->suspensionReason;
    }

    public function setSuspensionReason(?string $suspensionReason): self
    {
        $this->suspensionReason = $suspensionReason;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isTerminated(): bool
    {
        return $this->status === self::STATUS_TERMINATED;
    }

    public function suspend(User $suspendedBy, string $reason): self
    {
        $this->status = self::STATUS_SUSPENDED;
        $this->suspendedAt = new \DateTime();
        $this->suspendedBy = $suspendedBy;
        $this->suspensionReason = $reason;
        return $this;
    }

    public function activate(): self
    {
        $this->status = self::STATUS_ACTIVE;
        $this->suspendedAt = null;
        $this->suspendedBy = null;
        $this->suspensionReason = null;
        return $this;
    }

    public function terminate(): self
    {
        $this->status = self::STATUS_TERMINATED;
        return $this;
    }
}
