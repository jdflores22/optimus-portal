<?php

namespace App\Entity;

use App\Entity\Enum\AllocationStatus;
use App\Entity\Enum\ContainerStatus;
use App\Repository\ContainerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContainerRepository::class)]
#[ORM\Table(name: 'containers')]
class Container
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private string $containerNumber;

    #[ORM\ManyToOne(targetEntity: ContainerType::class)]
    #[ORM\JoinColumn(name: 'container_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ContainerType $containerType;

    #[ORM\ManyToOne(targetEntity: ContainerSize::class)]
    #[ORM\JoinColumn(name: 'container_size_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ContainerSize $containerSize;

    #[ORM\Column(type: 'string', enumType: ContainerStatus::class)]
    private ContainerStatus $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $currentLocation = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $expectedReturnDate;

    #[ORM\OneToMany(mappedBy: 'container', targetEntity: PreAdviceRequest::class)]
    private Collection $bookingRequests;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $terminalArrivalDate = null;
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $currentDwellTime = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastDwellTimeCalculation = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $dwellTimePausedAt = null;
    
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalPausedDays = 0;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $nextNotificationDate = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $automaticReturnDate = null;
    
    #[ORM\OneToMany(mappedBy: 'container', targetEntity: DwellTimeEvent::class)]
    private Collection $dwellTimeEvents;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(name: 'shipping_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLine $shippingLine = null;

    #[ORM\ManyToOne(targetEntity: NOA::class, inversedBy: 'containers')]
    #[ORM\JoinColumn(name: 'noa_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?NOA $noa = null;

    #[ORM\ManyToOne(targetEntity: Manifest::class)]
    #[ORM\JoinColumn(name: 'manifest_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Manifest $manifest = null;

    #[ORM\OneToMany(mappedBy: 'container', targetEntity: ElectronicDeliveryOrder::class, cascade: ['persist'])]
    private Collection $edos;

    #[ORM\ManyToOne(targetEntity: ShippingLineTerminalAllocation::class, inversedBy: 'containers')]
    #[ORM\JoinColumn(name: 'cy_allocation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLineTerminalAllocation $cyAllocation = null;

    #[ORM\Column(type: 'string', enumType: AllocationStatus::class, options: ['default' => 'pre_forecast'])]
    private AllocationStatus $allocationStatus = AllocationStatus::PRE_FORECAST;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $allocatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $allocationLockedAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->bookingRequests = new ArrayCollection();
        $this->dwellTimeEvents = new ArrayCollection();
        $this->edos = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getContainerNumber(): string
    {
        return $this->containerNumber;
    }

    public function setContainerNumber(string $containerNumber): self
    {
        $this->containerNumber = $containerNumber;
        return $this;
    }

    public function getStatus(): ContainerStatus
    {
        return $this->status;
    }

    public function setStatus(ContainerStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCurrentLocation(): ?string
    {
        return $this->currentLocation;
    }

    public function setCurrentLocation(?string $currentLocation): self
    {
        $this->currentLocation = $currentLocation;
        return $this;
    }

    public function getExpectedReturnDate(): \DateTime
    {
        return $this->expectedReturnDate;
    }

    public function setExpectedReturnDate(\DateTime $expectedReturnDate): self
    {
        $this->expectedReturnDate = $expectedReturnDate;
        return $this;
    }

    public function getBookingRequests(): Collection
    {
        return $this->bookingRequests;
    }

    public function addBookingRequest(PreAdviceRequest $bookingRequest): self
    {
        if (!$this->bookingRequests->contains($bookingRequest)) {
            $this->bookingRequests[] = $bookingRequest;
            $bookingRequest->setContainer($this);
        }

        return $this;
    }

    public function removeBookingRequest(PreAdviceRequest $bookingRequest): self
    {
        if ($this->bookingRequests->removeElement($bookingRequest)) {
            if ($bookingRequest->getContainer() === $this) {
                $bookingRequest->setContainer(null);
            }
        }

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

    public function getTerminalArrivalDate(): ?\DateTime
    {
        return $this->terminalArrivalDate;
    }

    public function setTerminalArrivalDate(?\DateTime $terminalArrivalDate): self
    {
        $this->terminalArrivalDate = $terminalArrivalDate;
        return $this;
    }

    public function getCurrentDwellTime(): ?int
    {
        return $this->currentDwellTime;
    }

    public function setCurrentDwellTime(?int $currentDwellTime): self
    {
        $this->currentDwellTime = $currentDwellTime;
        return $this;
    }

    public function getLastDwellTimeCalculation(): ?\DateTime
    {
        return $this->lastDwellTimeCalculation;
    }

    public function setLastDwellTimeCalculation(?\DateTime $lastDwellTimeCalculation): self
    {
        $this->lastDwellTimeCalculation = $lastDwellTimeCalculation;
        return $this;
    }

    public function getDwellTimePausedAt(): ?\DateTime
    {
        return $this->dwellTimePausedAt;
    }

    public function setDwellTimePausedAt(?\DateTime $dwellTimePausedAt): self
    {
        $this->dwellTimePausedAt = $dwellTimePausedAt;
        return $this;
    }

    public function getTotalPausedDays(): int
    {
        return $this->totalPausedDays;
    }

    public function setTotalPausedDays(int $totalPausedDays): self
    {
        $this->totalPausedDays = $totalPausedDays;
        return $this;
    }

    public function getNextNotificationDate(): ?\DateTime
    {
        return $this->nextNotificationDate;
    }

    public function setNextNotificationDate(?\DateTime $nextNotificationDate): self
    {
        $this->nextNotificationDate = $nextNotificationDate;
        return $this;
    }

    public function getAutomaticReturnDate(): ?\DateTime
    {
        return $this->automaticReturnDate;
    }

    public function setAutomaticReturnDate(?\DateTime $automaticReturnDate): self
    {
        $this->automaticReturnDate = $automaticReturnDate;
        return $this;
    }

    public function getDwellTimeEvents(): Collection
    {
        return $this->dwellTimeEvents;
    }

    public function addDwellTimeEvent(DwellTimeEvent $dwellTimeEvent): self
    {
        if (!$this->dwellTimeEvents->contains($dwellTimeEvent)) {
            $this->dwellTimeEvents[] = $dwellTimeEvent;
            $dwellTimeEvent->setContainer($this);
        }

        return $this;
    }

    public function removeDwellTimeEvent(DwellTimeEvent $dwellTimeEvent): self
    {
        if ($this->dwellTimeEvents->removeElement($dwellTimeEvent)) {
            if ($dwellTimeEvent->getContainer() === $this) {
                $dwellTimeEvent->setContainer(null);
            }
        }

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

    public function getContainerType(): ContainerType
    {
        return $this->containerType;
    }

    public function setContainerType(ContainerType $containerType): self
    {
        $this->containerType = $containerType;
        return $this;
    }

    public function getContainerSize(): ContainerSize
    {
        return $this->containerSize;
    }

    public function setContainerSize(ContainerSize $containerSize): self
    {
        $this->containerSize = $containerSize;
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

    public function getManifest(): ?Manifest
    {
        return $this->manifest;
    }

    public function setManifest(?Manifest $manifest): self
    {
        $this->manifest = $manifest;
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
            $edo->setContainer($this);
        }

        return $this;
    }

    public function removeEdo(ElectronicDeliveryOrder $edo): self
    {
        if ($this->edos->removeElement($edo)) {
            if ($edo->getContainer() === $this) {
                $edo->setContainer(null);
            }
        }

        return $this;
    }

    /**
     * Get the current active eDO for this container
     */
    public function getCurrentEDO(): ?ElectronicDeliveryOrder
    {
        $relevantStatuses = [
            \App\Entity\Enum\EDOStatus::PENDING_RELEASE,
            \App\Entity\Enum\EDOStatus::PENDING_VALIDATION,
            \App\Entity\Enum\EDOStatus::ACTIVE,
            \App\Entity\Enum\EDOStatus::RELEASED,
            \App\Entity\Enum\EDOStatus::LOCKED,
            \App\Entity\Enum\EDOStatus::EXPIRED,
        ];

        $latest = null;
        foreach ($this->edos as $edo) {
            if (!in_array($edo->getStatus(), $relevantStatuses, true)) {
                continue;
            }

            if ($latest === null || $edo->getGeneratedAt() > $latest->getGeneratedAt()) {
                $latest = $edo;
            }
        }

        return $latest;
    }

    /**
     * Get the complete eDO history for this container
     */
    public function getEDOHistory(): Collection
    {
        return $this->edos;
    }

    public function getCyAllocation(): ?ShippingLineTerminalAllocation
    {
        return $this->cyAllocation;
    }

    public function setCyAllocation(?ShippingLineTerminalAllocation $cyAllocation): self
    {
        $this->cyAllocation = $cyAllocation;
        return $this;
    }

    public function getAllocationStatus(): AllocationStatus
    {
        return $this->allocationStatus;
    }

    public function setAllocationStatus(AllocationStatus $allocationStatus): self
    {
        $this->allocationStatus = $allocationStatus;
        return $this;
    }

    public function getAllocatedAt(): ?\DateTime
    {
        return $this->allocatedAt;
    }

    public function setAllocatedAt(?\DateTime $allocatedAt): self
    {
        $this->allocatedAt = $allocatedAt;
        return $this;
    }

    public function getAllocationLockedAt(): ?\DateTime
    {
        return $this->allocationLockedAt;
    }

    public function setAllocationLockedAt(?\DateTime $allocationLockedAt): self
    {
        $this->allocationLockedAt = $allocationLockedAt;
        return $this;
    }

    /**
     * Check if the allocation is locked (cannot be modified)
     */
    public function isAllocationLocked(): bool
    {
        return $this->allocationStatus === AllocationStatus::ALLOCATED 
            || $this->allocationStatus === AllocationStatus::RELEASED;
    }

    /**
     * Check if the allocation can be modified
     */
    public function canModifyAllocation(): bool
    {
        return $this->allocationStatus === AllocationStatus::PRE_FORECAST;
    }
}