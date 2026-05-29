<?php

namespace App\Entity;

use App\Entity\Enum\PaymentType;
use App\Entity\Enum\PaymentStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\Index(name: 'idx_payments_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_payments_type_status', columns: ['payment_type', 'status'])]
#[ORM\Index(name: 'idx_payments_submitted_by', columns: ['submitted_by_id'])]
#[ORM\Index(name: 'idx_payments_shipping_line', columns: ['shipping_line_id'])]
#[ORM\Index(name: 'idx_payments_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_payments_version', columns: ['version'])]
#[ORM\Index(name: 'idx_payments_previous_payment', columns: ['previous_payment_id'])]
#[ORM\Index(name: 'idx_payments_manifest_type_version', columns: ['manifest_id', 'payment_type', 'version'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Manifest::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private ShippingLine $shippingLine;

    #[ORM\Column(type: 'string', enumType: PaymentType::class)]
    private PaymentType $paymentType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $amount;

    #[ORM\Column(type: 'string', length: 500)]
    private string $receiptFilePath;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $officialReceiptPath = null;

    #[ORM\Column(type: 'string', enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $submittedBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $validatedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validatedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'previous_payment_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Payment $previousPayment = null;

    public function __construct()
    {
        $this->status = PaymentStatus::PENDING_VALIDATION;
        $this->createdAt = new \DateTime();
    }

    public function verify(User $validator): void
    {
        $this->status = PaymentStatus::VERIFIED;
        $this->validatedBy = $validator;
        $this->validatedAt = new \DateTime();
        $this->rejectionReason = null;
    }

    public function reject(User $validator, string $reason): void
    {
        $this->status = PaymentStatus::REJECTED;
        $this->validatedBy = $validator;
        $this->validatedAt = new \DateTime();
        $this->rejectionReason = $reason;
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

    public function getShippingLine(): ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }

    public function getPaymentType(): PaymentType
    {
        return $this->paymentType;
    }

    public function setPaymentType(PaymentType $paymentType): self
    {
        $this->paymentType = $paymentType;
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

    public function getReceiptFilePath(): string
    {
        return $this->receiptFilePath;
    }

    public function setReceiptFilePath(string $receiptFilePath): self
    {
        $this->receiptFilePath = $receiptFilePath;
        return $this;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): self
    {
        $this->status = $status;
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

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function setValidatedBy(?User $validatedBy): self
    {
        $this->validatedBy = $validatedBy;
        return $this;
    }

    public function getValidatedAt(): ?\DateTimeInterface
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeInterface $validatedAt): self
    {
        $this->validatedAt = $validatedAt;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getOfficialReceiptPath(): ?string
    {
        return $this->officialReceiptPath;
    }

    public function setOfficialReceiptPath(?string $officialReceiptPath): self
    {
        $this->officialReceiptPath = $officialReceiptPath;
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getPreviousPayment(): ?Payment
    {
        return $this->previousPayment;
    }

    public function setPreviousPayment(?Payment $previousPayment): self
    {
        $this->previousPayment = $previousPayment;
        return $this;
    }

    public function isInitialVersion(): bool
    {
        return $this->version === 1;
    }

    public function isResubmission(): bool
    {
        return $this->previousPayment !== null;
    }

    public function getPaymentChain(): array
    {
        $chain = [];
        $current = $this;

        // Walk backwards to find the root (version 1)
        while ($current->getPreviousPayment() !== null) {
            $current = $current->getPreviousPayment();
        }

        // Build forward chain starting from root
        $chain[] = $current;

        // Note: This method only returns the chain up to the current payment
        // To get subsequent versions, use PaymentRepository methods
        return $chain;
    }
}
