<?php

namespace App\Entity;

use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\PaymentStatus;
use App\Repository\ElectronicDeliveryOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectronicDeliveryOrderRepository::class)]
#[ORM\Table(name: 'electronic_delivery_orders')]
#[ORM\Index(name: 'idx_edos_edo_number', columns: ['edo_number'])]
#[ORM\Index(name: 'idx_edos_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_edos_status', columns: ['status'])]
#[ORM\Index(name: 'idx_edos_shipping_line', columns: ['shipping_line_id'])]
#[ORM\Index(name: 'idx_edos_released_at', columns: ['released_at'])]
class ElectronicDeliveryOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $edoNumber;

    #[ORM\ManyToOne(targetEntity: Container::class, inversedBy: 'edos')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Container $container = null;

    #[ORM\ManyToOne(targetEntity: Manifest::class, inversedBy: 'edos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'electronicDeliveryOrders')]
    #[ORM\JoinColumn(nullable: false)]
    private ShippingLine $shippingLine;

    #[ORM\OneToOne(targetEntity: EDOPayment::class)]
    #[ORM\JoinColumn(name: 'edo_payment_id', nullable: true, unique: true)]
    private ?EDOPayment $edoPayment = null;

    #[ORM\OneToMany(targetEntity: EDOPayment::class, mappedBy: 'edo', cascade: ['persist'])]
    private Collection $payments;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?float $feeAmount = null;

    #[ORM\Column(type: 'string', length: 500)]
    private string $pdfPath;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $digitalSignature = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $generatedAt;

    #[ORM\Column(type: 'string', enumType: EDOStatus::class)]
    private EDOStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $releasedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $releasedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $expiredDays = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $cyLocation = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[ORM\Version]
    private int $version = 1;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'previous_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?ElectronicDeliveryOrder $previousVersion = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\OneToMany(targetEntity: EDOReleaseHistory::class, mappedBy: 'edo', cascade: ['persist', 'remove'])]
    private Collection $releaseHistory;

    public function __construct()
    {
        $this->generatedAt = new \DateTime();
        $this->status = EDOStatus::PENDING_RELEASE;
        $this->releaseHistory = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEdoNumber(): string
    {
        return $this->edoNumber;
    }

    public function setEdoNumber(string $edoNumber): self
    {
        $this->edoNumber = $edoNumber;
        return $this;
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

    public function getEdoPayment(): ?EDOPayment
    {
        return $this->edoPayment;
    }

    public function setEdoPayment(?EDOPayment $edoPayment): self
    {
        $this->edoPayment = $edoPayment;
        return $this;
    }

    public function getPdfPath(): string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function getDigitalSignature(): ?string
    {
        return $this->digitalSignature;
    }

    public function setDigitalSignature(?string $digitalSignature): self
    {
        $this->digitalSignature = $digitalSignature;
        return $this;
    }

    public function getGeneratedAt(): \DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function getStatus(): EDOStatus
    {
        return $this->status;
    }

    public function setStatus(EDOStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getReleasedBy(): ?User
    {
        return $this->releasedBy;
    }

    public function setReleasedBy(?User $releasedBy): self
    {
        $this->releasedBy = $releasedBy;
        return $this;
    }

    public function getReleasedAt(): ?\DateTimeInterface
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeInterface $releasedAt): self
    {
        $this->releasedAt = $releasedAt;
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

    /**
     * @return Collection<int, EDOReleaseHistory>
     */
    public function getReleaseHistory(): Collection
    {
        return $this->releaseHistory;
    }

    public function addReleaseHistory(EDOReleaseHistory $history): self
    {
        if (!$this->releaseHistory->contains($history)) {
            $this->releaseHistory->add($history);
            $history->setEdo($this);
        }
        return $this;
    }

    public function removeReleaseHistory(EDOReleaseHistory $history): self
    {
        if ($this->releaseHistory->removeElement($history)) {
            if ($history->getEdo() === $this) {
                $history->setEdo(null);
            }
        }
        return $this;
    }

    public function getContainer(): ?Container
    {
        return $this->container;
    }

    public function setContainer(?Container $container): self
    {
        $this->container = $container;
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

    public function getExpiredDays(): ?int
    {
        return $this->expiredDays;
    }

    public function setExpiredDays(?int $expiredDays): self
    {
        $this->expiredDays = $expiredDays;
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

    public function getPreviousVersion(): ?ElectronicDeliveryOrder
    {
        return $this->previousVersion;
    }

    public function setPreviousVersion(?ElectronicDeliveryOrder $previousVersion): self
    {
        $this->previousVersion = $previousVersion;
        return $this;
    }

    public function getCyLocation(): ?string
    {
        return $this->cyLocation;
    }

    public function setCyLocation(?string $cyLocation): self
    {
        $this->cyLocation = $cyLocation;
        return $this;
    }

    public function getFeeAmount(): ?float
    {
        return $this->feeAmount;
    }

    public function setFeeAmount(?float $feeAmount): self
    {
        $this->feeAmount = $feeAmount;
        return $this;
    }

    /**
     * @return Collection<int, EDOPayment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * Get the most recent payment submission
     */
    public function getCurrentPayment(): ?EDOPayment
    {
        $payments = $this->payments->toArray();
        if (empty($payments)) {
            return null;
        }
        usort($payments, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        return $payments[0];
    }

    /**
     * Check if eDO has pending payment
     */
    public function hasPendingPayment(): bool
    {
        foreach ($this->payments as $payment) {
            if ($payment->getStatus() === PaymentStatus::PENDING_VALIDATION) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate eDO number with format: EDO-YYYYMMDD-CONTAINER-XXXX
     */
    public static function generateEdoNumber(string $containerNumber, int $sequence): string
    {
        $date = (new \DateTime())->format('Ymd');
        $sequenceStr = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        // Sanitize container number to remove special characters
        $sanitizedContainer = preg_replace('/[^A-Z0-9]/', '', strtoupper($containerNumber));
        return sprintf('EDO-%s-%s-%s', $date, $sanitizedContainer, $sequenceStr);
    }
}
