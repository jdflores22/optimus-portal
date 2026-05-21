<?php

namespace App\Entity;

use App\Repository\NotificationMetricsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationMetricsRepository::class)]
#[ORM\Table(name: 'notification_metrics')]
#[ORM\Index(columns: ['sent_at'], name: 'idx_sent_at')]
#[ORM\Index(columns: ['notification_type'], name: 'idx_notification_type')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_id')]
class NotificationMetrics
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Notification $notification = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $notificationType = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $failedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(length: 20)]
    private string $deliveryStatus = 'pending'; // pending, delivered, failed, opened

    public function __construct()
    {
        $this->sentAt = new \DateTime();
        $this->deliveryStatus = 'pending';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotification(): ?Notification
    {
        return $this->notification;
    }

    public function setNotification(?Notification $notification): static
    {
        $this->notification = $notification;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getNotificationType(): ?string
    {
        return $this->notificationType;
    }

    public function setNotificationType(string $notificationType): static
    {
        $this->notificationType = $notificationType;
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeInterface $sentAt): static
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeInterface
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeInterface $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;
        if ($deliveredAt && $this->deliveryStatus === 'pending') {
            $this->deliveryStatus = 'delivered';
        }
        return $this;
    }

    public function getOpenedAt(): ?\DateTimeInterface
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeInterface $openedAt): static
    {
        $this->openedAt = $openedAt;
        if ($openedAt) {
            $this->deliveryStatus = 'opened';
        }
        return $this;
    }

    public function getFailedAt(): ?\DateTimeInterface
    {
        return $this->failedAt;
    }

    public function setFailedAt(?\DateTimeInterface $failedAt): static
    {
        $this->failedAt = $failedAt;
        if ($failedAt) {
            $this->deliveryStatus = 'failed';
        }
        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): static
    {
        $this->failureReason = $failureReason;
        return $this;
    }

    public function getDeliveryStatus(): string
    {
        return $this->deliveryStatus;
    }

    public function setDeliveryStatus(string $deliveryStatus): static
    {
        $this->deliveryStatus = $deliveryStatus;
        return $this;
    }

    public function markAsDelivered(): static
    {
        $this->setDeliveredAt(new \DateTime());
        return $this;
    }

    public function markAsOpened(): static
    {
        $this->setOpenedAt(new \DateTime());
        return $this;
    }

    public function markAsFailed(string $reason): static
    {
        $this->setFailedAt(new \DateTime());
        $this->setFailureReason($reason);
        return $this;
    }
}
