<?php

namespace App\Entity;

use App\Repository\ManifestRepository;
use App\Entity\Enum\WorkflowState;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ManifestRepository::class)]
#[ORM\Table(name: 'manifests')]
#[ORM\Index(name: 'idx_manifests_manifest_number', columns: ['manifest_number'])]
#[ORM\Index(name: 'idx_manifests_consignee', columns: ['consignee_id'])]
#[ORM\Index(name: 'idx_manifests_broker', columns: ['broker_id'])]
#[ORM\Index(name: 'idx_manifests_workflow_state', columns: ['workflow_state'])]
#[ORM\Index(name: 'idx_manifests_shipping_line', columns: ['shipping_line_id'])]
#[ORM\Index(name: 'idx_manifests_created_at', columns: ['created_at'])]
class Manifest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $manifestNumber;

    #[ORM\ManyToOne(targetEntity: Consignee::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Consignee $consignee = null;

    #[ORM\ManyToOne(targetEntity: Broker::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Broker $broker = null;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'manifests')]
    #[ORM\JoinColumn(nullable: false)]
    private ShippingLine $shippingLine;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $vesselName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $voyageNumber = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $arrivalDate = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $blNumber = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $blFilePath = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $manifestFilePath = null;

    #[ORM\Column(type: 'string', enumType: WorkflowState::class)]
    private WorkflowState $workflowState;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $archivedForBroker = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'completed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'previous_broker_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $previousBroker = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $transferredAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $brokerInactiveSince = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'manifest')]
    private Collection $payments;

    #[ORM\OneToMany(targetEntity: EDOPayment::class, mappedBy: 'manifest')]
    private Collection $edoPayments;

    #[ORM\OneToOne(targetEntity: Billing::class, mappedBy: 'manifest')]
    private ?Billing $billing = null;

    #[ORM\OneToOne(targetEntity: NOADocument::class, mappedBy: 'manifest')]
    private ?NOADocument $noaDocument = null;

    #[ORM\OneToMany(targetEntity: ElectronicDeliveryOrder::class, mappedBy: 'manifest')]
    private Collection $edos;

    #[ORM\ManyToOne(targetEntity: NOA::class)]
    #[ORM\JoinColumn(name: 'noa_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?NOA $noa = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->workflowState = WorkflowState::MANIFEST_UPLOADED;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->payments = new ArrayCollection();
        $this->edoPayments = new ArrayCollection();
        $this->edos = new ArrayCollection();
    }

    public function canTransitionTo(WorkflowState $newState): bool
    {
        return WorkflowState::isValidTransition($this->workflowState, $newState);
    }

    public function transitionTo(WorkflowState $newState): void
    {
        if (!$this->canTransitionTo($newState)) {
            throw new \InvalidArgumentException(
                "Invalid state transition from {$this->workflowState->value} to {$newState->value}"
            );
        }
        $this->workflowState = $newState;
        $this->updatedAt = new \DateTime();
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

    public function getConsignee(): ?Consignee
    {
        return $this->consignee;
    }

    public function setConsignee(?Consignee $consignee): self
    {
        $this->consignee = $consignee;
        return $this;
    }

    public function getBroker(): ?Broker
    {
        return $this->broker;
    }

    public function setBroker(?Broker $broker): self
    {
        $this->broker = $broker;
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

    public function getVesselName(): ?string
    {
        return $this->vesselName;
    }

    public function setVesselName(?string $vesselName): self
    {
        $this->vesselName = $vesselName;
        return $this;
    }

    public function getVoyageNumber(): ?string
    {
        return $this->voyageNumber;
    }

    public function setVoyageNumber(?string $voyageNumber): self
    {
        $this->voyageNumber = $voyageNumber;
        return $this;
    }

    public function getArrivalDate(): ?\DateTimeInterface
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(?\DateTimeInterface $arrivalDate): self
    {
        $this->arrivalDate = $arrivalDate;
        return $this;
    }

    public function getBlNumber(): ?string
    {
        return $this->blNumber;
    }

    public function setBlNumber(?string $blNumber): self
    {
        $this->blNumber = $blNumber;
        return $this;
    }

    public function getBlFilePath(): ?string
    {
        return $this->blFilePath;
    }

    public function setBlFilePath(?string $blFilePath): self
    {
        $this->blFilePath = $blFilePath;
        return $this;
    }

    public function getManifestFilePath(): ?string
    {
        return $this->manifestFilePath;
    }

    public function setManifestFilePath(?string $manifestFilePath): self
    {
        $this->manifestFilePath = $manifestFilePath;
        return $this;
    }

    public function getWorkflowState(): WorkflowState
    {
        return $this->workflowState;
    }

    public function setWorkflowState(WorkflowState $workflowState): self
    {
        $this->workflowState = $workflowState;
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
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setManifest($this);
        }

        return $this;
    }

    public function removePayment(Payment $payment): self
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getManifest() === $this) {
                $payment->setManifest(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EDOPayment>
     */
    public function getEdoPayments(): Collection
    {
        return $this->edoPayments;
    }

    public function addEdoPayment(EDOPayment $edoPayment): self
    {
        if (!$this->edoPayments->contains($edoPayment)) {
            $this->edoPayments->add($edoPayment);
            $edoPayment->setManifest($this);
        }

        return $this;
    }

    public function removeEdoPayment(EDOPayment $edoPayment): self
    {
        $this->edoPayments->removeElement($edoPayment);

        return $this;
    }

    public function getBilling(): ?Billing
    {
        return $this->billing;
    }

    public function setBilling(?Billing $billing): self
    {
        $this->billing = $billing;
        return $this;
    }

    public function getNoaDocument(): ?NOADocument
    {
        return $this->noaDocument;
    }

    public function setNoaDocument(?NOADocument $noaDocument): self
    {
        $this->noaDocument = $noaDocument;
        return $this;
    }

    /**
     * @return Collection<int, ElectronicDeliveryOrder>
     */
    public function getEdos(): Collection
    {
        return $this->edos;
    }

    public function addEdo(ElectronicDeliveryOrder $edo): self
    {
        if (!$this->edos->contains($edo)) {
            $this->edos->add($edo);
            $edo->setManifest($this);
        }
        return $this;
    }

    public function removeEdo(ElectronicDeliveryOrder $edo): self
    {
        if ($this->edos->removeElement($edo)) {
            // Set the owning side to null (unless already changed)
            if ($edo->getManifest() === $this) {
                $edo->setManifest(null);
            }
        }
        return $this;
    }

    /**
     * Get the first eDO (for backward compatibility)
     * @deprecated Use getEdos() instead
     */
    public function getEdo(): ?ElectronicDeliveryOrder
    {
        return $this->edos->first() ?: null;
    }

    /**
     * Set a single eDO (for backward compatibility)
     * @deprecated Use addEdo() instead
     */
    public function setEdo(?ElectronicDeliveryOrder $edo): self
    {
        if ($edo !== null) {
            $this->addEdo($edo);
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isArchivedForBroker(): bool
    {
        return $this->archivedForBroker;
    }

    public function setArchivedForBroker(bool $archivedForBroker): self
    {
        $this->archivedForBroker = $archivedForBroker;
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

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function setCompletedBy(?User $completedBy): self
    {
        $this->completedBy = $completedBy;
        return $this;
    }

    public function getPreviousBroker(): ?User
    {
        return $this->previousBroker;
    }

    public function setPreviousBroker(?User $previousBroker): self
    {
        $this->previousBroker = $previousBroker;
        return $this;
    }

    public function getTransferredAt(): ?\DateTimeInterface
    {
        return $this->transferredAt;
    }

    public function setTransferredAt(?\DateTimeInterface $transferredAt): self
    {
        $this->transferredAt = $transferredAt;
        return $this;
    }

    public function getBrokerInactiveSince(): ?\DateTimeInterface
    {
        return $this->brokerInactiveSince;
    }

    public function setBrokerInactiveSince(?\DateTimeInterface $brokerInactiveSince): self
    {
        $this->brokerInactiveSince = $brokerInactiveSince;
        return $this;
    }

    public function getNoa(): ?NOA
    {
        return $this->noa;
    }

    public function setNoa(?NOA $noa): self
    {
        $this->noa = $noa;
        return $this;
    }

    public function markAsCompleted(User $completedBy): self
    {
        $this->completedAt = new \DateTime();
        $this->completedBy = $completedBy;
        $this->archivedForBroker = true;
        return $this;
    }

    public function transferBroker(User $newBroker): self
    {
        $this->previousBroker = $this->broker;
        $this->broker = $newBroker;
        $this->transferredAt = new \DateTime();
        return $this;
    }

    /**
     * Get the manifest access payment for this manifest
     */
    public function getManifestAccessPayment(): ?EDOPayment
    {
        foreach ($this->edoPayments as $payment) {
            return $payment; // Only one eDO payment per manifest
        }
        return null;
    }

    /**
     * Get the final payment for this manifest
     */
    public function getFinalPayment(): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->getPaymentType()->value === 'final_payment') {
                return $payment;
            }
        }
        return null;
    }

    /**
     * Get containers that are linked to this manifest
     * 
     * @return Collection<int, Container>
     */
    public function getContainersLinkedToManifest(): Collection
    {
        if (!$this->noa) {
            return new ArrayCollection();
        }
        
        return $this->noa->getContainers()->filter(
            fn(\App\Entity\Container $container) => $container->getManifest() === $this
        );
    }
}
