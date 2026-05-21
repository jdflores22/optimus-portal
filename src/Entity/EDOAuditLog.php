<?php

namespace App\Entity;

use App\Entity\Enum\AuditEventType;
use App\Repository\EDOAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EDOAuditLogRepository::class)]
#[ORM\Table(name: 'edo_audit_logs')]
#[ORM\Index(name: 'idx_edo_audit_edo', columns: ['edo_id'])]
#[ORM\Index(name: 'idx_edo_audit_container', columns: ['container_id'])]
#[ORM\Index(name: 'idx_edo_audit_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_edo_audit_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_edo_audit_timestamp', columns: ['timestamp'])]
#[ORM\Index(name: 'idx_edo_audit_batch_session', columns: ['batch_session_id'])]
class EDOAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'eDO is required')]
    private ElectronicDeliveryOrder $edo;

    #[ORM\ManyToOne(targetEntity: Container::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Container is required')]
    private Container $container;

    #[ORM\Column(type: 'string', enumType: AuditEventType::class)]
    #[Assert\NotNull(message: 'Event type is required')]
    private AuditEventType $eventType;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'User is required')]
    private User $user;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $timestamp;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $batchSessionId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $batchSequence = null;

    public function __construct()
    {
        $this->timestamp = new \DateTime();
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

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function getEventType(): AuditEventType
    {
        return $this->eventType;
    }

    public function setEventType(AuditEventType $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function setDetails(?array $details): self
    {
        $this->details = $details;
        return $this;
    }

    public function getTimestamp(): \DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getBatchSessionId(): ?string
    {
        return $this->batchSessionId;
    }

    public function setBatchSessionId(?string $batchSessionId): self
    {
        $this->batchSessionId = $batchSessionId;
        return $this;
    }

    public function getBatchSequence(): ?int
    {
        return $this->batchSequence;
    }

    public function setBatchSequence(?int $batchSequence): self
    {
        $this->batchSequence = $batchSequence;
        return $this;
    }
}
