<?php

namespace App\Entity;

use App\Entity\Enum\RenewalRequestStatus;
use App\Repository\EDORenewalRequestRepository;
use App\Util\DecimalString;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EDORenewalRequestRepository::class)]
#[ORM\Table(name: 'edo_renewal_requests')]
#[ORM\Index(name: 'idx_renewal_requests_status', columns: ['status'])]
#[ORM\Index(name: 'idx_renewal_requests_requested_at', columns: ['requested_at'])]
#[ORM\Index(name: 'idx_renewal_requests_expired_edo', columns: ['expired_edo_id'])]
class EDORenewalRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'expired_edo_id', nullable: false, onDelete: 'CASCADE')]
    private ElectronicDeliveryOrder $expiredEdo;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'new_edo_id', nullable: true, onDelete: 'SET NULL')]
    private ?ElectronicDeliveryOrder $newEdo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: false)]
    private User $requestedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $requestedAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $emptyContainerReturnDate;

    #[ORM\Column(type: 'integer')]
    private int $overdueDays;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $detentionChargeAmount = '0.00';

    #[ORM\Column(type: 'string', enumType: RenewalRequestStatus::class)]
    private RenewalRequestStatus $status;

    #[ORM\ManyToOne(targetEntity: Billing::class)]
    #[ORM\JoinColumn(name: 'detention_billing_id', nullable: true, onDelete: 'SET NULL')]
    private ?Billing $detentionBilling = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $paymentVerified = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $paymentVerifiedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'payment_verified_by_id', nullable: true)]
    private ?User $paymentVerifiedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $additionalNotes = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTime();
        $this->status = RenewalRequestStatus::PENDING_REVIEW;
        $this->overdueDays = 0;
        $this->detentionChargeAmount = '0.00';
        $this->paymentVerified = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExpiredEdo(): ElectronicDeliveryOrder
    {
        return $this->expiredEdo;
    }

    public function setExpiredEdo(ElectronicDeliveryOrder $expiredEdo): self
    {
        $this->expiredEdo = $expiredEdo;
        return $this;
    }

    public function getNewEdo(): ?ElectronicDeliveryOrder
    {
        return $this->newEdo;
    }

    public function setNewEdo(?ElectronicDeliveryOrder $newEdo): self
    {
        $this->newEdo = $newEdo;
        return $this;
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

    public function getRequestedAt(): \DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeInterface $requestedAt): self
    {
        $this->requestedAt = $requestedAt;
        return $this;
    }

    public function getEmptyContainerReturnDate(): \DateTimeInterface
    {
        return $this->emptyContainerReturnDate;
    }

    public function setEmptyContainerReturnDate(\DateTimeInterface $emptyContainerReturnDate): self
    {
        $this->emptyContainerReturnDate = $emptyContainerReturnDate;
        return $this;
    }

    public function getOverdueDays(): int
    {
        return $this->overdueDays;
    }

    public function setOverdueDays(int $overdueDays): self
    {
        $this->overdueDays = $overdueDays;
        return $this;
    }

    public function getDetentionChargeAmount(): float
    {
        return DecimalString::toFloatOrZero($this->detentionChargeAmount);
    }

    public function setDetentionChargeAmount(float $detentionChargeAmount): self
    {
        $this->detentionChargeAmount = DecimalString::fromFloat($detentionChargeAmount) ?? '0.00';
        return $this;
    }

    public function getStatus(): RenewalRequestStatus
    {
        return $this->status;
    }

    public function setStatus(RenewalRequestStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDetentionBilling(): ?Billing
    {
        return $this->detentionBilling;
    }

    public function setDetentionBilling(?Billing $detentionBilling): self
    {
        $this->detentionBilling = $detentionBilling;
        return $this;
    }

    public function isPaymentVerified(): bool
    {
        return $this->paymentVerified;
    }

    public function setPaymentVerified(bool $paymentVerified): self
    {
        $this->paymentVerified = $paymentVerified;
        return $this;
    }

    public function getPaymentVerifiedAt(): ?\DateTimeInterface
    {
        return $this->paymentVerifiedAt;
    }

    public function setPaymentVerifiedAt(?\DateTimeInterface $paymentVerifiedAt): self
    {
        $this->paymentVerifiedAt = $paymentVerifiedAt;
        return $this;
    }

    public function getPaymentVerifiedBy(): ?User
    {
        return $this->paymentVerifiedBy;
    }

    public function setPaymentVerifiedBy(?User $paymentVerifiedBy): self
    {
        $this->paymentVerifiedBy = $paymentVerifiedBy;
        return $this;
    }

    public function getAdditionalNotes(): ?string
    {
        return $this->additionalNotes;
    }

    public function setAdditionalNotes(?string $additionalNotes): self
    {
        $this->additionalNotes = $additionalNotes;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}
