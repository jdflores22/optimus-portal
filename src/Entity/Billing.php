<?php

namespace App\Entity;

use App\Util\DecimalString;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'billings')]
#[ORM\Index(name: 'idx_billings_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_billings_type', columns: ['billing_type'])]
#[ORM\Index(name: 'idx_billings_renewal_request', columns: ['edo_renewal_request_id'])]
#[ORM\Index(name: 'idx_billings_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_billings_version', columns: ['version'])]
#[ORM\Index(name: 'idx_billings_previous', columns: ['previous_billing_id'])]
class Billing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Manifest::class, inversedBy: 'billing')]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'CASCADE')]
    private ?Manifest $manifest = null;

    #[ORM\Column(type: 'string', length: 50, options: ['default' => 'manifest'])]
    private string $billingType = 'manifest';

    #[ORM\ManyToOne(targetEntity: EDORenewalRequest::class)]
    #[ORM\JoinColumn(name: 'edo_renewal_request_id', nullable: true, onDelete: 'SET NULL')]
    private ?EDORenewalRequest $edoRenewalRequest = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $detentionDays = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $detentionRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $freightCharges = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $thcCharges = '0.00';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $additionalCharges = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: 'string', length: 3, options: ['default' => 'PHP'])]
    private string $originalCurrency = 'PHP';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $freightChargesUsd = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $thcChargesUsd = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $totalAmountUsd = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $receiptFilePath = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $paymentSubmittedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'payment_submitted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $paymentSubmittedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $generatedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: Billing::class)]
    #[ORM\JoinColumn(name: 'previous_billing_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Billing $previousBilling = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function computeTotal(): void
    {
        $additional = 0;
        if ($this->additionalCharges) {
            foreach ($this->additionalCharges as $charge) {
                $additional += $charge['amount'] ?? 0;
            }
        }
        $this->totalAmount = DecimalString::fromFloat(
            DecimalString::toFloatOrZero($this->freightCharges)
            + DecimalString::toFloatOrZero($this->thcCharges)
            + $additional
        ) ?? '0.00';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManifest(): ?Manifest
    {
        return $this->manifest;
    }

    public function setManifest(?Manifest $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
    }

    public function getBillingType(): string
    {
        return $this->billingType;
    }

    public function setBillingType(string $billingType): self
    {
        $this->billingType = $billingType;
        return $this;
    }

    public function getEdoRenewalRequest(): ?EDORenewalRequest
    {
        return $this->edoRenewalRequest;
    }

    public function setEdoRenewalRequest(?EDORenewalRequest $edoRenewalRequest): self
    {
        $this->edoRenewalRequest = $edoRenewalRequest;
        return $this;
    }

    public function getDetentionDays(): ?int
    {
        return $this->detentionDays;
    }

    public function setDetentionDays(?int $detentionDays): self
    {
        $this->detentionDays = $detentionDays;
        return $this;
    }

    public function getDetentionRate(): ?float
    {
        return DecimalString::toFloat($this->detentionRate);
    }

    public function setDetentionRate(?float $detentionRate): self
    {
        $this->detentionRate = DecimalString::fromFloat($detentionRate);
        return $this;
    }

    public function getFreightCharges(): float
    {
        return DecimalString::toFloatOrZero($this->freightCharges);
    }

    public function setFreightCharges(float $freightCharges): self
    {
        $this->freightCharges = DecimalString::fromFloat($freightCharges) ?? '0.00';
        return $this;
    }

    public function getThcCharges(): float
    {
        return DecimalString::toFloatOrZero($this->thcCharges);
    }

    public function setThcCharges(float $thcCharges): self
    {
        $this->thcCharges = DecimalString::fromFloat($thcCharges) ?? '0.00';
        return $this;
    }

    public function getAdditionalCharges(): ?array
    {
        return $this->additionalCharges;
    }

    public function setAdditionalCharges(?array $additionalCharges): self
    {
        $this->additionalCharges = $additionalCharges;
        return $this;
    }

    public function getTotalAmount(): float
    {
        return DecimalString::toFloatOrZero($this->totalAmount);
    }

    public function setTotalAmount(float $totalAmount): self
    {
        $this->totalAmount = DecimalString::fromFloat($totalAmount) ?? '0.00';
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function getReceiptFilePath(): ?string
    {
        return $this->receiptFilePath;
    }

    public function setReceiptFilePath(?string $receiptFilePath): self
    {
        $this->receiptFilePath = $receiptFilePath;
        return $this;
    }

    public function getPaymentSubmittedAt(): ?\DateTimeInterface
    {
        return $this->paymentSubmittedAt;
    }

    public function setPaymentSubmittedAt(?\DateTimeInterface $paymentSubmittedAt): self
    {
        $this->paymentSubmittedAt = $paymentSubmittedAt;
        return $this;
    }

    public function getPaymentSubmittedBy(): ?User
    {
        return $this->paymentSubmittedBy;
    }

    public function setPaymentSubmittedBy(?User $paymentSubmittedBy): self
    {
        $this->paymentSubmittedBy = $paymentSubmittedBy;
        return $this;
    }

    public function getGeneratedBy(): User
    {
        return $this->generatedBy;
    }

    public function setGeneratedBy(User $generatedBy): self
    {
        $this->generatedBy = $generatedBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getOriginalCurrency(): string
    {
        return $this->originalCurrency;
    }

    public function setOriginalCurrency(string $originalCurrency): self
    {
        $this->originalCurrency = $originalCurrency;
        return $this;
    }

    public function getExchangeRate(): ?float
    {
        return DecimalString::toFloat($this->exchangeRate);
    }

    public function setExchangeRate(?float $exchangeRate): self
    {
        $this->exchangeRate = DecimalString::fromFloat($exchangeRate, 4);
        return $this;
    }

    public function getFreightChargesUsd(): ?float
    {
        return DecimalString::toFloat($this->freightChargesUsd);
    }

    public function setFreightChargesUsd(?float $freightChargesUsd): self
    {
        $this->freightChargesUsd = DecimalString::fromFloat($freightChargesUsd);
        return $this;
    }

    public function getThcChargesUsd(): ?float
    {
        return DecimalString::toFloat($this->thcChargesUsd);
    }

    public function setThcChargesUsd(?float $thcChargesUsd): self
    {
        $this->thcChargesUsd = DecimalString::fromFloat($thcChargesUsd);
        return $this;
    }

    public function getTotalAmountUsd(): ?float
    {
        return DecimalString::toFloat($this->totalAmountUsd);
    }

    public function setTotalAmountUsd(?float $totalAmountUsd): self
    {
        $this->totalAmountUsd = DecimalString::fromFloat($totalAmountUsd);
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

    public function getPreviousBilling(): ?Billing
    {
        return $this->previousBilling;
    }

    public function setPreviousBilling(?Billing $previousBilling): self
    {
        $this->previousBilling = $previousBilling;
        return $this;
    }

    public function isInitialVersion(): bool
    {
        return $this->version === 1;
    }

    public function isResubmission(): bool
    {
        return $this->previousBilling !== null;
    }
}
