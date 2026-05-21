<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'edo_access_logs')]
class EDOAccessLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class, inversedBy: 'accessLogs')]
    #[ORM\JoinColumn(name: 'edo_id', referencedColumnName: 'id', nullable: false)]
    private ElectronicDeliveryOrder $edo;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'accessed_by_id', referencedColumnName: 'id', nullable: false)]
    private User $accessedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $accessedAt;

    #[ORM\Column(type: 'string', length: 45)]
    private string $ipAddress;

    #[ORM\Column(type: 'string', length: 20)]
    private string $accessResult;

    public function getId(): int
    {
        return $this->id;
    }

    public function getEdo(): ElectronicDeliveryOrder
    {
        return $this->edo;
    }

    public function setEdo(ElectronicDeliveryOrder $edo): self
    {
        $this->edo = $edo;
        return $this;
    }

    public function getAccessedBy(): User
    {
        return $this->accessedBy;
    }

    public function setAccessedBy(User $accessedBy): self
    {
        $this->accessedBy = $accessedBy;
        return $this;
    }

    public function getAccessedAt(): \DateTimeInterface
    {
        return $this->accessedAt;
    }

    public function setAccessedAt(\DateTimeInterface $accessedAt): self
    {
        $this->accessedAt = $accessedAt;
        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getAccessResult(): string
    {
        return $this->accessResult;
    }

    public function setAccessResult(string $accessResult): self
    {
        $this->accessResult = $accessResult;
        return $this;
    }
}