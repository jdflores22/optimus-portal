<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'staff_users')]
class StaffUser extends User
{
    #[ORM\Column(type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $department;

    #[ORM\OneToMany(mappedBy: 'staffUser', targetEntity: ShippingLineTerminalAllocation::class)]
    private Collection $terminalAllocations;

    public function __construct()
    {
        parent::__construct();
        $this->terminalAllocations = new ArrayCollection();
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function setDepartment(string $department): self
    {
        $this->department = $department;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getTerminalAllocations(): Collection
    {
        return $this->terminalAllocations;
    }

    public function addTerminalAllocation(ShippingLineTerminalAllocation $allocation): self
    {
        if (!$this->terminalAllocations->contains($allocation)) {
            $this->terminalAllocations[] = $allocation;
            $allocation->setStaffUser($this);
        }

        return $this;
    }

    public function removeTerminalAllocation(ShippingLineTerminalAllocation $allocation): self
    {
        if ($this->terminalAllocations->removeElement($allocation)) {
            if ($allocation->getStaffUser() === $this) {
                $allocation->setStaffUser(null);
            }
        }

        return $this;
    }

    public function getAllocatedTerminals(): Collection
    {
        return $this->terminalAllocations->map(function(ShippingLineTerminalAllocation $allocation) {
            return $allocation->getTerminal();
        });
    }
}
