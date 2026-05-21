<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'brokers')]
class Broker extends User
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $fullName;

    #[ORM\OneToMany(targetEntity: Consignee::class, mappedBy: 'linkedBroker')]
    private Collection $linkedConsignees;

    public function __construct()
    {
        parent::__construct();
        $this->linkedConsignees = new ArrayCollection();
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;
        return $this;
    }

    /**
     * @return Collection<int, Consignee>
     */
    public function getLinkedConsignees(): Collection
    {
        return $this->linkedConsignees;
    }

    public function addLinkedConsignee(Consignee $consignee): self
    {
        if (!$this->linkedConsignees->contains($consignee)) {
            $this->linkedConsignees->add($consignee);
            $consignee->setLinkedBroker($this);
        }

        return $this;
    }

    public function removeLinkedConsignee(Consignee $consignee): self
    {
        if ($this->linkedConsignees->removeElement($consignee)) {
            if ($consignee->getLinkedBroker() === $this) {
                $consignee->setLinkedBroker(null);
            }
        }

        return $this;
    }
}
