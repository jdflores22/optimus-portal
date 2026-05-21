<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shipment_records')]
#[ORM\Index(columns: ['manifest_number'], name: 'idx_manifest_number')]
class ShipmentRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $manifestNumber;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $noticeOfArrivalDate;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $actualArrivalDate = null;

    #[ORM\Column(type: 'text')]
    private string $billingInformation;

    #[ORM\ManyToOne(targetEntity: Consignee::class)]
    #[ORM\JoinColumn(name: 'consignee_id', referencedColumnName: 'id', nullable: true)]
    private ?Consignee $consignee = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $deliveryOrderNo = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $blNo = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $vessel = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $voyage = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lloydsNo = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $generalDeclarationDt = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $vesselCustomNo = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $agentCustomRegNo = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $custId = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $custStatus = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $custRef = null;

    // Container Information
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $containerNumber = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $containerType = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $containerSize = null;

    // Commodity Information
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $commodity = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $commodityPcs = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $commodityQty = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $netWtKgm = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $measCbm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $emptyReturnAddress = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false)]
    private User $createdBy;

    #[ORM\ManyToMany(targetEntity: Broker::class)]
    #[ORM\JoinTable(name: 'shipment_broker_access')]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'broker_id', referencedColumnName: 'id')]
    private Collection $authorizedBrokers;

    #[ORM\OneToMany(targetEntity: PaymentVerification::class, mappedBy: 'shipment')]
    private Collection $payments;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->authorizedBrokers = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManifestNumber(): string
    {
        return $this->manifestNumber;
    }

    public function setManifestNumber(string $manifestNumber): self
    {
        $this->manifestNumber = $manifestNumber;
        return $this;
    }

    public function getNoticeOfArrivalDate(): \DateTimeInterface
    {
        return $this->noticeOfArrivalDate;
    }

    public function setNoticeOfArrivalDate(\DateTimeInterface $noticeOfArrivalDate): self
    {
        $this->noticeOfArrivalDate = $noticeOfArrivalDate;
        return $this;
    }

    public function getActualArrivalDate(): ?\DateTimeInterface
    {
        return $this->actualArrivalDate;
    }

    public function setActualArrivalDate(?\DateTimeInterface $actualArrivalDate): self
    {
        $this->actualArrivalDate = $actualArrivalDate;
        return $this;
    }

    public function getBillingInformation(): string
    {
        return $this->billingInformation;
    }

    public function setBillingInformation(string $billingInformation): self
    {
        $this->billingInformation = $billingInformation;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return Collection<int, Broker>
     */
    public function getAuthorizedBrokers(): Collection
    {
        return $this->authorizedBrokers;
    }

    public function addAuthorizedBroker(Broker $broker): self
    {
        if (!$this->authorizedBrokers->contains($broker)) {
            $this->authorizedBrokers->add($broker);
        }

        return $this;
    }

    public function removeAuthorizedBroker(Broker $broker): self
    {
        $this->authorizedBrokers->removeElement($broker);
        return $this;
    }

    public function isAuthorizedForBroker(Broker $broker): bool
    {
        return $this->authorizedBrokers->contains($broker);
    }

    /**
     * @return Collection<int, PaymentVerification>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * Get the payment verification for this shipment (assumes one payment per shipment)
     */
    public function getPayment(): ?PaymentVerification
    {
        return $this->payments->first() ?: null;
    }

    public function addPayment(PaymentVerification $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setShipment($this);
        }

        return $this;
    }

    public function removePayment(PaymentVerification $payment): self
    {
        if ($this->payments->removeElement($payment)) {
            // set the owning side to null (unless already changed)
            if ($payment->getShipment() === $this) {
                $payment->setShipment(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getConsignee(): ?Consignee
    {
        return $this->consignee;
    }

    public function setConsignee(?Consignee $consignee): self
    {
        $this->consignee = $consignee;
        return $this;
    }

    public function getDeliveryOrderNo(): ?string
    {
        return $this->deliveryOrderNo;
    }

    public function setDeliveryOrderNo(?string $deliveryOrderNo): self
    {
        $this->deliveryOrderNo = $deliveryOrderNo;
        return $this;
    }

    public function getBlNo(): ?string
    {
        return $this->blNo;
    }

    public function setBlNo(?string $blNo): self
    {
        $this->blNo = $blNo;
        return $this;
    }

    public function getVessel(): ?string
    {
        return $this->vessel;
    }

    public function setVessel(?string $vessel): self
    {
        $this->vessel = $vessel;
        return $this;
    }

    public function getVoyage(): ?string
    {
        return $this->voyage;
    }

    public function setVoyage(?string $voyage): self
    {
        $this->voyage = $voyage;
        return $this;
    }

    public function getLloydsNo(): ?string
    {
        return $this->lloydsNo;
    }

    public function setLloydsNo(?string $lloydsNo): self
    {
        $this->lloydsNo = $lloydsNo;
        return $this;
    }

    public function getGeneralDeclarationDt(): ?\DateTimeInterface
    {
        return $this->generalDeclarationDt;
    }

    public function setGeneralDeclarationDt(?\DateTimeInterface $generalDeclarationDt): self
    {
        $this->generalDeclarationDt = $generalDeclarationDt;
        return $this;
    }

    public function getVesselCustomNo(): ?string
    {
        return $this->vesselCustomNo;
    }

    public function setVesselCustomNo(?string $vesselCustomNo): self
    {
        $this->vesselCustomNo = $vesselCustomNo;
        return $this;
    }

    public function getAgentCustomRegNo(): ?string
    {
        return $this->agentCustomRegNo;
    }

    public function setAgentCustomRegNo(?string $agentCustomRegNo): self
    {
        $this->agentCustomRegNo = $agentCustomRegNo;
        return $this;
    }

    public function getCustId(): ?string
    {
        return $this->custId;
    }

    public function setCustId(?string $custId): self
    {
        $this->custId = $custId;
        return $this;
    }

    public function getCustStatus(): ?string
    {
        return $this->custStatus;
    }

    public function setCustStatus(?string $custStatus): self
    {
        $this->custStatus = $custStatus;
        return $this;
    }

    public function getCustRef(): ?string
    {
        return $this->custRef;
    }

    public function setCustRef(?string $custRef): self
    {
        $this->custRef = $custRef;
        return $this;
    }

    // Container Information Getters and Setters
    public function getContainerNumber(): ?string
    {
        return $this->containerNumber;
    }

    public function setContainerNumber(?string $containerNumber): self
    {
        $this->containerNumber = $containerNumber;
        return $this;
    }

    public function getContainerType(): ?string
    {
        return $this->containerType;
    }

    public function setContainerType(?string $containerType): self
    {
        $this->containerType = $containerType;
        return $this;
    }

    public function getContainerSize(): ?string
    {
        return $this->containerSize;
    }

    public function setContainerSize(?string $containerSize): self
    {
        $this->containerSize = $containerSize;
        return $this;
    }

    // Commodity Information Getters and Setters
    public function getCommodity(): ?string
    {
        return $this->commodity;
    }

    public function setCommodity(?string $commodity): self
    {
        $this->commodity = $commodity;
        return $this;
    }

    public function getCommodityPcs(): ?string
    {
        return $this->commodityPcs;
    }

    public function setCommodityPcs(?string $commodityPcs): self
    {
        $this->commodityPcs = $commodityPcs;
        return $this;
    }

    public function getCommodityQty(): ?string
    {
        return $this->commodityQty;
    }

    public function setCommodityQty(?string $commodityQty): self
    {
        $this->commodityQty = $commodityQty;
        return $this;
    }

    public function getNetWtKgm(): ?string
    {
        return $this->netWtKgm;
    }

    public function setNetWtKgm(?string $netWtKgm): self
    {
        $this->netWtKgm = $netWtKgm;
        return $this;
    }

    public function getMeasCbm(): ?string
    {
        return $this->measCbm;
    }

    public function setMeasCbm(?string $measCbm): self
    {
        $this->measCbm = $measCbm;
        return $this;
    }

    public function getEmptyReturnAddress(): ?string
    {
        return $this->emptyReturnAddress;
    }

    public function setEmptyReturnAddress(?string $emptyReturnAddress): self
    {
        $this->emptyReturnAddress = $emptyReturnAddress;
        return $this;
    }
}
