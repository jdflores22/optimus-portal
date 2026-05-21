<?php

namespace App\Entity;

use App\Repository\ShippingLineTerminalAllocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingLineTerminalAllocationRepository::class)]
#[ORM\Table(name: 'shipping_line_terminal_allocations')]
#[ORM\UniqueConstraint(name: 'unique_shipping_line_staff_terminal', columns: ['shipping_line_id', 'staff_user_id', 'terminal_id'])]
class ShippingLineTerminalAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: StaffUser::class)]
    #[ORM\JoinColumn(name: 'staff_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StaffUser $staffUser;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(name: 'shipping_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShippingLine $shippingLine;

    #[ORM\ManyToOne(targetEntity: Terminal::class)]
    #[ORM\JoinColumn(name: 'terminal_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Terminal $terminal;

    #[ORM\Column(type: 'integer')]
    private int $allocatedCapacity = 0;

    #[ORM\Column(name: 'capacity_20ft', type: 'integer', options: ['default' => 0])]
    private int $capacity20ft = 0;

    #[ORM\Column(name: 'capacity_40ft', type: 'integer', options: ['default' => 0])]
    private int $capacity40ft = 0;

    #[ORM\OneToMany(mappedBy: 'cyAllocation', targetEntity: Container::class)]
    private Collection $containers;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->containers = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStaffUser(): StaffUser
    {
        return $this->staffUser;
    }

    public function setStaffUser(StaffUser $staffUser): self
    {
        $this->staffUser = $staffUser;
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

    public function getTerminal(): Terminal
    {
        return $this->terminal;
    }

    public function setTerminal(Terminal $terminal): self
    {
        $this->terminal = $terminal;
        return $this;
    }

    public function getAllocatedCapacity(): int
    {
        return $this->allocatedCapacity;
    }

    public function setAllocatedCapacity(int $allocatedCapacity): self
    {
        $this->allocatedCapacity = $allocatedCapacity;
        return $this;
    }

    public function getCapacity20ft(): int
    {
        return $this->capacity20ft;
    }

    public function setCapacity20ft(int $capacity20ft): self
    {
        $this->capacity20ft = $capacity20ft;
        return $this;
    }

    public function getCapacity40ft(): int
    {
        return $this->capacity40ft;
    }

    public function setCapacity40ft(int $capacity40ft): self
    {
        $this->capacity40ft = $capacity40ft;
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

    /**
     * @return Collection<int, Container>
     */
    public function getContainers(): Collection
    {
        if (!isset($this->containers)) {
            $this->containers = new ArrayCollection();
        }
        return $this->containers;
    }

    /**
     * Calculate current utilization in TEU
     */
    public function getCurrentUtilizationTEU(): float
    {
        $totalTEU = 0.0;
        
        foreach ($this->getContainers() as $container) {
            $size = $container->getContainerSize();
            $totalTEU += $size->getTeuValue();
        }
        
        return $totalTEU;
    }

    /**
     * Calculate available capacity in TEU
     */
    public function getAvailableCapacityTEU(): float
    {
        return $this->allocatedCapacity - $this->getCurrentUtilizationTEU();
    }

    /**
     * Check if allocation has capacity for the required TEU
     */
    public function hasCapacityFor(float $teuRequired): bool
    {
        return $this->getAvailableCapacityTEU() >= $teuRequired;
    }
}