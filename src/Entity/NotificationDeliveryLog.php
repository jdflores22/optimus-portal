<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks notification delivery attempts and status for monitoring
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_delivery_logs')]
#[ORM\Index(columns: ['container_id'], name: 'idx_container')]
#[ORM\Index(columns: ['notification_type'], name: 'idx_notification_type')]
#[ORM\Index(columns: ['delivery_status'], name: 'idx_delivery_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
class NotificationDeliveryLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
    
    #[ORM\ManyToOne(targetEntity: Container::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Container $container;
    
    #[ORM\Column(type: 'string', length: 50)]
    private string $notificationType; // 'dwell_time_warning', 'automatic_return', etc.
    
    #[ORM\Column(type: 'string', length: 20)]
    private string $deliveryStatus; // 'pending', 'delivered', 'failed', 'retrying'
    
    #[ORM\Column(type: 'string', length: 20)]
    private string $channel; // 'email', 'sms', 'in_app'
    
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $recipient;
    
    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $deliveredAt = null;
    
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attemptCount = 0;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastAttemptAt = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;
    
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->deliveryStatus = 'pending';
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

    public function getNotificationType(): string
    {
        return $this->notificationType;
    }

    public function setNotificationType(string $notificationType): self
    {
        $this->notificationType = $notificationType;
        return $this;
    }

    public function getDeliveryStatus(): string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(string $deliveryStatus): self
    {
        $this->deliveryStatus = $deliveryStatus;
        
        if ($deliveryStatus === 'delivered' && !$this->deliveredAt) {
            $this->deliveredAt = new \DateTime();
        }
        
        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function setRecipient(User $recipient): self
    {
        $this->recipient = $recipient;
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

    public function getDeliveredAt(): ?\DateTime
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTime $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): self
    {
        $this->attemptCount = $attemptCount;
        return $this;
    }

    public function incrementAttemptCount(): self
    {
        $this->attemptCount++;
        $this->lastAttemptAt = new \DateTime();
        return $this;
    }

    public function getLastAttemptAt(): ?\DateTime
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?\DateTime $lastAttemptAt): self
    {
        $this->lastAttemptAt = $lastAttemptAt;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
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

    public function markAsDelivered(): self
    {
        $this->deliveryStatus = 'delivered';
        $this->deliveredAt = new \DateTime();
        return $this;
    }

    public function markAsFailed(string $errorMessage): self
    {
        $this->deliveryStatus = 'failed';
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function markAsRetrying(): self
    {
        $this->deliveryStatus = 'retrying';
        return $this;
    }
}
