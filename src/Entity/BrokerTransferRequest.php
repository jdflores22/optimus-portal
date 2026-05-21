<?php

namespace App\Entity;

use App\Repository\BrokerTransferRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BrokerTransferRequestRepository::class)]
#[ORM\Table(name: 'broker_transfer_requests')]
#[ORM\Index(name: 'idx_btr_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_btr_status', columns: ['status'])]
#[ORM\Index(name: 'idx_btr_consignee', columns: ['consignee_id'])]
class BrokerTransferRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Manifest::class)]
    #[ORM\JoinColumn(name: 'manifest_id', nullable: false, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'consignee_id', nullable: false, onDelete: 'CASCADE')]
    private User $consignee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'old_broker_id', nullable: false, onDelete: 'CASCADE')]
    private User $oldBroker;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'new_broker_id', nullable: false, onDelete: 'CASCADE')]
    private User $newBroker;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $reason;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $transferLetter = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_PENDING])]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $requestedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $requestedBy;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNotes = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function setManifest(Manifest $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
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

    public function getOldBroker(): User
    {
        return $this->oldBroker;
    }

    public function setOldBroker(User $oldBroker): self
    {
        $this->oldBroker = $oldBroker;
        return $this;
    }

    public function getNewBroker(): User
    {
        return $this->newBroker;
    }

    public function setNewBroker(User $newBroker): self
    {
        $this->newBroker = $newBroker;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getTransferLetter(): ?string
    {
        return $this->transferLetter;
    }

    public function setTransferLetter(?string $transferLetter): self
    {
        $this->transferLetter = $transferLetter;
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

    public function getRequestedAt(): \DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(User $requestedBy): self
    {
        $this->requestedBy = $requestedBy;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeInterface $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function setReviewNotes(?string $reviewNotes): self
    {
        $this->reviewNotes = $reviewNotes;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function approve(User $reviewer): self
    {
        $this->status = self::STATUS_APPROVED;
        $this->reviewedAt = new \DateTime();
        $this->reviewedBy = $reviewer;
        return $this;
    }

    public function reject(User $reviewer, string $notes): self
    {
        $this->status = self::STATUS_REJECTED;
        $this->reviewedAt = new \DateTime();
        $this->reviewedBy = $reviewer;
        $this->reviewNotes = $notes;
        return $this;
    }
}
