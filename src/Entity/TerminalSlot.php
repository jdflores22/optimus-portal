<?php

namespace App\Entity;

use App\Entity\Enum\SlotStatus;
use App\Repository\TerminalSlotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TerminalSlotRepository::class)]
#[ORM\Table(name: 'terminal_slots')]
class TerminalSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Terminal::class, inversedBy: 'slots')]
    #[ORM\JoinColumn(nullable: false)]
    private Terminal $terminal;

    #[ORM\Column(type: 'date')]
    private \DateTime $date;

    #[ORM\Column(type: 'integer')]
    private int $capacity;

    #[ORM\Column(type: 'integer')]
    private int $assignedCount = 0;

    #[ORM\Column(type: 'string', enumType: SlotStatus::class)]
    private SlotStatus $status;

    #[ORM\OneToMany(mappedBy: 'assignedSlot', targetEntity: PreAdviceRequest::class)]
    private Collection $preAdviceRequests;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->preAdviceRequests = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->status = SlotStatus::AVAILABLE;
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getDate(): \DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): self
    {
        $this->capacity = $capacity;
        return $this;
    }

    public function getAssignedCount(): int
    {
        return $this->assignedCount;
    }

    public function setAssignedCount(int $assignedCount): self
    {
        $this->assignedCount = $assignedCount;
        return $this;
    }

    public function getStatus(): SlotStatus
    {
        return $this->status;
    }

    public function setStatus(SlotStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getPreAdviceRequests(): Collection
    {
        return $this->preAdviceRequests;
    }

    public function addPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if (!$this->preAdviceRequests->contains($preAdviceRequest)) {
            $this->preAdviceRequests[] = $preAdviceRequest;
            $preAdviceRequest->setAssignedSlot($this);
        }

        return $this;
    }

    public function removePreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if ($this->preAdviceRequests->removeElement($preAdviceRequest)) {
            if ($preAdviceRequest->getAssignedSlot() === $this) {
                $preAdviceRequest->setAssignedSlot(null);
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

    public function isAvailable(): bool
    {
        return $this->status === SlotStatus::AVAILABLE && $this->assignedCount < $this->capacity;
    }

    public function incrementAssignedCount(): self
    {
        $this->assignedCount++;
        if ($this->assignedCount >= $this->capacity) {
            $this->status = SlotStatus::FULL;
        }
        return $this;
    }

    public function decrementAssignedCount(): self
    {
        if ($this->assignedCount > 0) {
            $this->assignedCount--;
            if ($this->assignedCount < $this->capacity && $this->status === SlotStatus::FULL) {
                $this->status = SlotStatus::AVAILABLE;
            }
        }
        return $this;
    }
}