<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'container_allocation_audit')]
#[ORM\Index(columns: ['container_id'], name: 'idx_audit_container')]
#[ORM\Index(columns: ['changed_at'], name: 'idx_audit_changed_at')]
#[ORM\Index(columns: ['change_type'], name: 'idx_audit_change_type')]
class ContainerAllocationAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Container::class)]
    #[ORM\JoinColumn(name: 'container_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Container $container;

    #[ORM\ManyToOne(targetEntity: ShippingLineTerminalAllocation::class)]
    #[ORM\JoinColumn(name: 'previous_allocation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLineTerminalAllocation $previousAllocation = null;

    #[ORM\ManyToOne(targetEntity: ShippingLineTerminalAllocation::class)]
    #[ORM\JoinColumn(name: 'new_allocation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShippingLineTerminalAllocation $newAllocation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'changed_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $changedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $changedAt;

    #[ORM\Column(type: 'string', length: 50)]
    private string $changeType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->changedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function getPreviousAllocation(): ?ShippingLineTerminalAllocation
    {
        return $this->previousAllocation;
    }

    public function setPreviousAllocation(?ShippingLineTerminalAllocation $previousAllocation): self
    {
        $this->previousAllocation = $previousAllocation;
        return $this;
    }

    public function getNewAllocation(): ShippingLineTerminalAllocation
    {
        return $this->newAllocation;
    }

    public function setNewAllocation(ShippingLineTerminalAllocation $newAllocation): self
    {
        $this->newAllocation = $newAllocation;
        return $this;
    }

    public function getChangedBy(): User
    {
        return $this->changedBy;
    }

    public function setChangedBy(User $changedBy): self
    {
        $this->changedBy = $changedBy;
        return $this;
    }

    public function getChangedAt(): \DateTime
    {
        return $this->changedAt;
    }

    public function setChangedAt(\DateTime $changedAt): self
    {
        $this->changedAt = $changedAt;
        return $this;
    }

    public function getChangeType(): string
    {
        return $this->changeType;
    }

    public function setChangeType(string $changeType): self
    {
        $this->changeType = $changeType;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }
}
