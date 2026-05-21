<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'consignees')]
class Consignee extends User
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $businessName;

    #[ORM\ManyToOne(targetEntity: Broker::class, inversedBy: 'linkedConsignees')]
    #[ORM\JoinColumn(name: 'broker_id', referencedColumnName: 'id', nullable: true)]
    private ?Broker $linkedBroker = null;

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): self
    {
        $this->businessName = $businessName;
        return $this;
    }

    public function getLinkedBroker(): ?Broker
    {
        return $this->linkedBroker;
    }

    public function setLinkedBroker(?Broker $linkedBroker): self
    {
        $this->linkedBroker = $linkedBroker;
        return $this;
    }
}
