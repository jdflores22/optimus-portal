<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'billings')]
#[ORM\Index(name: 'idx_billings_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_billings_created_at', columns: ['created_at'])]
class Billing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Manifest::class, inversedBy: 'billing')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $freightCharges;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $thcCharges;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $additionalCharges = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $totalAmount;

    #[ORM\Column(type: 'string', length: 3, options: ['default' => 'PHP'])]
    private string $originalCurrency = 'PHP';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?float $exchangeRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $freightChargesUsd = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $thcChargesUsd = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $totalAmountUsd = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $generatedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

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
        $this->totalAmount = $this->freightCharges + $this->thcCharges + $additional;
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

    public function getFreightCharges(): float
    {
        return $this->freightCharges;
    }

    public function setFreightCharges(float $freightCharges): self
    {
        $this->freightCharges = $freightCharges;
        return $this;
    }

    public function getThcCharges(): float
    {
        return $this->thcCharges;
    }

    public function setThcCharges(float $thcCharges): self
    {
        $this->thcCharges = $thcCharges;
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
        return $this->totalAmount;
    }

    public function setTotalAmount(float $totalAmount): self
    {
        $this->totalAmount = $totalAmount;
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
        return $this->exchangeRate;
    }

    public function setExchangeRate(?float $exchangeRate): self
    {
        $this->exchangeRate = $exchangeRate;
        return $this;
    }

    public function getFreightChargesUsd(): ?float
    {
        return $this->freightChargesUsd;
    }

    public function setFreightChargesUsd(?float $freightChargesUsd): self
    {
        $this->freightChargesUsd = $freightChargesUsd;
        return $this;
    }

    public function getThcChargesUsd(): ?float
    {
        return $this->thcChargesUsd;
    }

    public function setThcChargesUsd(?float $thcChargesUsd): self
    {
        $this->thcChargesUsd = $thcChargesUsd;
        return $this;
    }

    public function getTotalAmountUsd(): ?float
    {
        return $this->totalAmountUsd;
    }

    public function setTotalAmountUsd(?float $totalAmountUsd): self
    {
        $this->totalAmountUsd = $totalAmountUsd;
        return $this;
    }
}
