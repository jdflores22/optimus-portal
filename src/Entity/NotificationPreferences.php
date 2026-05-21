<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_preferences')]
class NotificationPreferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'json')]
    private array $enabledTypes = [];

    #[ORM\Column(name: 'do_not_disturb_enabled', type: 'boolean')]
    private bool $doNotDisturbEnabled = false;

    #[ORM\Column(name: 'do_not_disturb_start', type: 'time', nullable: true)]
    private ?\DateTimeInterface $doNotDisturbStart = null;

    #[ORM\Column(name: 'do_not_disturb_end', type: 'time', nullable: true)]
    private ?\DateTimeInterface $doNotDisturbEnd = null;

    public function __construct()
    {
        // Default: all notification types enabled
        $this->enabledTypes = [
            'manifest_payment_required',
            'manifest_consignee_declared',
            'manifest_access_granted',
            'noa_generated',
            'billing_generated',
            'payment_rejected',
            'payment_submitted',
            'payment_approved',
            'payment_validated',
            'bl_uploaded',
            'edo_generated',
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEnabledTypes(): array
    {
        return $this->enabledTypes;
    }

    public function setEnabledTypes(array $enabledTypes): self
    {
        $this->enabledTypes = $enabledTypes;
        return $this;
    }

    public function isTypeEnabled(string $type): bool
    {
        return in_array($type, $this->enabledTypes, true);
    }

    public function enableType(string $type): self
    {
        if (!in_array($type, $this->enabledTypes, true)) {
            $this->enabledTypes[] = $type;
        }
        return $this;
    }

    public function disableType(string $type): self
    {
        $this->enabledTypes = array_values(
            array_filter($this->enabledTypes, fn($t) => $t !== $type)
        );
        return $this;
    }

    public function isDoNotDisturbEnabled(): bool
    {
        return $this->doNotDisturbEnabled;
    }

    public function setDoNotDisturbEnabled(bool $doNotDisturbEnabled): self
    {
        $this->doNotDisturbEnabled = $doNotDisturbEnabled;
        return $this;
    }

    public function getDoNotDisturbStart(): ?\DateTimeInterface
    {
        return $this->doNotDisturbStart;
    }

    public function setDoNotDisturbStart(?\DateTimeInterface $doNotDisturbStart): self
    {
        $this->doNotDisturbStart = $doNotDisturbStart;
        return $this;
    }

    public function getDoNotDisturbEnd(): ?\DateTimeInterface
    {
        return $this->doNotDisturbEnd;
    }

    public function setDoNotDisturbEnd(?\DateTimeInterface $doNotDisturbEnd): self
    {
        $this->doNotDisturbEnd = $doNotDisturbEnd;
        return $this;
    }

    /**
     * Check if Do Not Disturb mode is currently active
     */
    public function isInDoNotDisturbPeriod(): bool
    {
        if (!$this->doNotDisturbEnabled || !$this->doNotDisturbStart || !$this->doNotDisturbEnd) {
            return false;
        }

        $now = new \DateTime();
        $currentTime = $now->format('H:i:s');
        $startTime = $this->doNotDisturbStart->format('H:i:s');
        $endTime = $this->doNotDisturbEnd->format('H:i:s');

        // Handle case where DND period crosses midnight
        if ($startTime > $endTime) {
            return $currentTime >= $startTime || $currentTime < $endTime;
        }

        return $currentTime >= $startTime && $currentTime < $endTime;
    }
}
