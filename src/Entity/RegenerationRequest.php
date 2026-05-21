<?php

namespace App\Entity;

use App\Entity\Enum\RequestStatus;
use App\Repository\RegenerationRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RegenerationRequestRepository::class)]
#[ORM\Table(name: 'regeneration_requests')]
#[ORM\Index(name: 'idx_regen_req_edo', columns: ['edo_id'])]
#[ORM\Index(name: 'idx_regen_req_requester', columns: ['requester_id'])]
#[ORM\Index(name: 'idx_regen_req_status', columns: ['status'])]
#[ORM\Index(name: 'idx_regen_req_requested_at', columns: ['requested_at'])]
class RegenerationRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'eDO is required')]
    private ElectronicDeliveryOrder $edo;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Requester is required')]
    private User $requester;

    #[ORM\Column(type: 'string', enumType: RequestStatus::class)]
    private RequestStatus $status;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $requestedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $routedToAccountingAt = null;

    #[ORM\OneToOne(targetEntity: EDOBilling::class, mappedBy: 'regenerationRequest')]
    private ?EDOBilling $billing = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->status = RequestStatus::SUBMITTED;
        $this->requestedAt = new \DateTime();
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

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function setRequester(User $requester): self
    {
        $this->requester = $requester;
        return $this;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function setStatus(RequestStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRequestedAt(): \DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeInterface $requestedAt): self
    {
        $this->requestedAt = $requestedAt;
        return $this;
    }

    public function getRoutedToAccountingAt(): ?\DateTimeInterface
    {
        return $this->routedToAccountingAt;
    }

    public function setRoutedToAccountingAt(?\DateTimeInterface $routedToAccountingAt): self
    {
        $this->routedToAccountingAt = $routedToAccountingAt;
        return $this;
    }

    public function getBilling(): ?EDOBilling
    {
        return $this->billing;
    }

    public function setBilling(?EDOBilling $billing): self
    {
        $this->billing = $billing;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }
}
