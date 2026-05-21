<?php

namespace App\Entity;

use App\Entity\Enum\PreAdviceStatus;
use App\Repository\PreAdviceRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PreAdviceRequestRepository::class)]
#[ORM\Table(name: 'pre_advice_requests')]
class PreAdviceRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Trucker::class, inversedBy: 'preAdviceRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private Trucker $trucker;

    #[ORM\ManyToOne(targetEntity: Container::class, inversedBy: 'bookingRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private Container $container;

    #[ORM\ManyToOne(targetEntity: Terminal::class, inversedBy: 'preAdviceRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private Terminal $selectedTerminal;

    #[ORM\ManyToOne(targetEntity: TerminalSlot::class, inversedBy: 'preAdviceRequests')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TerminalSlot $assignedSlot = null;

    #[ORM\OneToMany(mappedBy: 'preAdviceRequest', targetEntity: GeotagPhoto::class, cascade: ['persist', 'remove'])]
    private Collection $geotagPhotos;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(name: 'shipping_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLine $shippingLine = null;

    #[ORM\Column(type: 'string', enumType: PreAdviceStatus::class)]
    private PreAdviceStatus $status;

    #[ORM\ManyToOne(targetEntity: TerminalTeamUser::class, inversedBy: 'verifiedPreAdviceRequests')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TerminalTeamUser $verifiedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $verifiedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $paymentReference = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $paymentVerified = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $paymentVerifiedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $paymentFailureCount = 0;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $lastPaymentFailureReason = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastPaymentFailureAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $qrCode = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $edoNumber = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->geotagPhotos = new ArrayCollection();
        $this->status = PreAdviceStatus::PENDING;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTrucker(): Trucker
    {
        return $this->trucker;
    }

    public function setTrucker(Trucker $trucker): self
    {
        $this->trucker = $trucker;
        return $this;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function getSelectedTerminal(): Terminal
    {
        return $this->selectedTerminal;
    }

    public function setSelectedTerminal(Terminal $selectedTerminal): self
    {
        $this->selectedTerminal = $selectedTerminal;
        return $this;
    }

    public function getAssignedSlot(): ?TerminalSlot
    {
        return $this->assignedSlot;
    }

    public function setAssignedSlot(?TerminalSlot $assignedSlot): self
    {
        $this->assignedSlot = $assignedSlot;
        return $this;
    }

    public function getGeotagPhotos(): Collection
    {
        return $this->geotagPhotos;
    }

    public function addGeotagPhoto(GeotagPhoto $geotagPhoto): self
    {
        if (!$this->geotagPhotos->contains($geotagPhoto)) {
            $this->geotagPhotos[] = $geotagPhoto;
            $geotagPhoto->setPreAdviceRequest($this);
        }

        return $this;
    }

    public function removeGeotagPhoto(GeotagPhoto $geotagPhoto): self
    {
        if ($this->geotagPhotos->removeElement($geotagPhoto)) {
            if ($geotagPhoto->getPreAdviceRequest() === $this) {
                $geotagPhoto->setPreAdviceRequest(null);
            }
        }

        return $this;
    }

    public function getStatus(): PreAdviceStatus
    {
        return $this->status;
    }

    public function setStatus(PreAdviceStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getVerifiedBy(): ?TerminalTeamUser
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?TerminalTeamUser $verifiedBy): self
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTime
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTime $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;
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

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): self
    {
        $this->paymentReference = $paymentReference;
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

    public function getPaymentVerifiedAt(): ?\DateTime
    {
        return $this->paymentVerifiedAt;
    }

    public function setPaymentVerifiedAt(?\DateTime $paymentVerifiedAt): self
    {
        $this->paymentVerifiedAt = $paymentVerifiedAt;
        return $this;
    }

    public function getPaymentFailureCount(): int
    {
        return $this->paymentFailureCount;
    }

    public function setPaymentFailureCount(int $paymentFailureCount): self
    {
        $this->paymentFailureCount = $paymentFailureCount;
        return $this;
    }

    public function getLastPaymentFailureReason(): ?string
    {
        return $this->lastPaymentFailureReason;
    }

    public function setLastPaymentFailureReason(?string $lastPaymentFailureReason): self
    {
        $this->lastPaymentFailureReason = $lastPaymentFailureReason;
        return $this;
    }

    public function getLastPaymentFailureAt(): ?\DateTime
    {
        return $this->lastPaymentFailureAt;
    }

    public function setLastPaymentFailureAt(?\DateTime $lastPaymentFailureAt): self
    {
        $this->lastPaymentFailureAt = $lastPaymentFailureAt;
        return $this;
    }

    public function getQrCode(): ?string
    {
        return $this->qrCode;
    }

    public function setQrCode(?string $qrCode): self
    {
        $this->qrCode = $qrCode;
        return $this;
    }

    public function getEdoNumber(): ?string
    {
        return $this->edoNumber;
    }

    public function setEdoNumber(?string $edoNumber): self
    {
        $this->edoNumber = $edoNumber;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getShippingLine(): ?ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(?ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }
}