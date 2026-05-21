<?php

namespace App\Entity;

use App\Entity\Enum\EDOPaymentReceiptStatus;
use App\Repository\EDOPaymentReceiptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EDOPaymentReceiptRepository::class)]
#[ORM\Table(name: 'edo_payment_receipts')]
#[ORM\Index(name: 'idx_edo_payment_billing', columns: ['billing_id'])]
#[ORM\Index(name: 'idx_edo_payment_submitter', columns: ['submitted_by_id'])]
#[ORM\Index(name: 'idx_edo_payment_status', columns: ['status'])]
#[ORM\Index(name: 'idx_edo_payment_submitted_at', columns: ['submitted_at'])]
class EDOPaymentReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: EDOBilling::class, inversedBy: 'payment')]
    #[ORM\JoinColumn(name: 'billing_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Billing is required')]
    private EDOBilling $billing;

    #[ORM\Column(type: 'string', length: 500)]
    #[Assert\NotBlank(message: 'Receipt file path is required')]
    private string $receiptFilePath;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Submitted by user is required')]
    private User $submittedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $submittedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'confirmed_by_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $confirmedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $confirmedAt = null;

    #[ORM\Column(type: 'string', enumType: EDOPaymentReceiptStatus::class)]
    private EDOPaymentReceiptStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    public function __construct()
    {
        $this->status = EDOPaymentReceiptStatus::SUBMITTED;
        $this->submittedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBilling(): EDOBilling
    {
        return $this->billing;
    }

    public function setBilling(EDOBilling $billing): self
    {
        $this->billing = $billing;
        return $this;
    }

    public function getReceiptFilePath(): string
    {
        return $this->receiptFilePath;
    }

    public function setReceiptFilePath(string $receiptFilePath): self
    {
        $this->receiptFilePath = $receiptFilePath;
        return $this;
    }

    public function getSubmittedBy(): User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(User $submittedBy): self
    {
        $this->submittedBy = $submittedBy;
        return $this;
    }

    public function getSubmittedAt(): \DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeInterface $submittedAt): self
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): self
    {
        $this->confirmedBy = $confirmedBy;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeInterface
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeInterface $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getStatus(): EDOPaymentReceiptStatus
    {
        return $this->status;
    }

    public function setStatus(EDOPaymentReceiptStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }
}
