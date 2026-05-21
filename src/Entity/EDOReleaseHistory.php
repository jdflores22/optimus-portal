<?php

namespace App\Entity;

use App\Entity\Enum\EDOStatus;
use App\Repository\EDOReleaseHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EDOReleaseHistoryRepository::class)]
#[ORM\Table(name: 'edo_release_history')]
#[ORM\Index(name: 'idx_edo_id', columns: ['edo_id'])]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
class EDOReleaseHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class, inversedBy: 'releaseHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ElectronicDeliveryOrder $edo;

    #[ORM\Column(type: 'string', enumType: EDOStatus::class)]
    private EDOStatus $fromStatus;

    #[ORM\Column(type: 'string', enumType: EDOStatus::class)]
    private EDOStatus $toStatus;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
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

    public function getFromStatus(): EDOStatus
    {
        return $this->fromStatus;
    }

    public function setFromStatus(EDOStatus $fromStatus): self
    {
        $this->fromStatus = $fromStatus;
        return $this;
    }

    public function getToStatus(): EDOStatus
    {
        return $this->toStatus;
    }

    public function setToStatus(EDOStatus $toStatus): self
    {
        $this->toStatus = $toStatus;
        return $this;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function setActor(User $actor): self
    {
        $this->actor = $actor;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
