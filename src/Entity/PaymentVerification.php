<?php

namespace App\Entity;

use App\Entity\Enum\PaymentStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_verifications')]
class PaymentVerification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShipmentRecord::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id', nullable: false)]
    private ShipmentRecord $shipment;

    #[ORM\ManyToOne(targetEntity: Broker::class)]
    #[ORM\JoinColumn(name: 'broker_id', referencedColumnName: 'id', nullable: false)]
    private Broker $broker;

    #[ORM\Column(type: 'string', length: 500)]
    private string $proofFilePath;

    #[ORM\Column(type: 'string', enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'verified_by_id', referencedColumnName: 'id', nullable: true)]
    private ?User $verifiedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = PaymentStatus::PENDING_VALIDATION;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ShipmentRecord
    {
        return $this->shipment;
    }

    public function setShipment(ShipmentRecord $shipment): self
    {
        $this->shipment = $shipment;
        return $this;
    }

    public function getBroker(): Broker
    {
        return $this->broker;
    }

    public function setBroker(Broker $broker): self
    {
        $this->broker = $broker;
        return $this;
    }

    public function getProofFilePath(): string
    {
        return $this->proofFilePath;
    }

    public function setProofFilePath(string $proofFilePath): self
    {
        $this->proofFilePath = $proofFilePath;
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

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?User $verifiedBy): self
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
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
