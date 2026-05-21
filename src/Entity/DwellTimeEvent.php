<?php

namespace App\Entity;

use App\Entity\Enum\DwellTimeEventType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dwell_time_events')]
class DwellTimeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
    
    #[ORM\ManyToOne(targetEntity: Container::class, inversedBy: 'dwellTimeEvents')]
    #[ORM\JoinColumn(nullable: false)]
    private Container $container;
    
    #[ORM\Column(type: 'string', enumType: DwellTimeEventType::class)]
    private DwellTimeEventType $eventType;
    
    #[ORM\Column(type: 'datetime')]
    private \DateTime $eventDate;
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dwellTimeAtEvent = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;
    
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;
    
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $triggeredBy = null;

    public function __construct()
    {
        $this->eventDate = new \DateTime();
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

    public function getEventType(): DwellTimeEventType
    {
        return $this->eventType;
    }

    public function setEventType(DwellTimeEventType $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getEventDate(): \DateTime
    {
        return $this->eventDate;
    }

    public function setEventDate(\DateTime $eventDate): self
    {
        $this->eventDate = $eventDate;
        return $this;
    }

    public function getDwellTimeAtEvent(): ?int
    {
        return $this->dwellTimeAtEvent;
    }

    public function setDwellTimeAtEvent(?int $dwellTimeAtEvent): self
    {
        $this->dwellTimeAtEvent = $dwellTimeAtEvent;
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

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(?User $triggeredBy): self
    {
        $this->triggeredBy = $triggeredBy;
        return $this;
    }
}