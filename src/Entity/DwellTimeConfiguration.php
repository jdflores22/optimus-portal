<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dwell_time_configuration')]
class DwellTimeConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
    
    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $notificationThresholdDays = 60;
    
    #[ORM\Column(type: 'integer', options: ['default' => 90])]
    private int $automaticReturnThresholdDays = 90;
    
    #[ORM\Column(type: 'string', length: 50, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';
    
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enableAutomaticReturns = true;
    
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enableNotifications = true;

    public function getId(): int
    {
        return $this->id;
    }

    public function getNotificationThresholdDays(): int
    {
        return $this->notificationThresholdDays;
    }

    public function setNotificationThresholdDays(int $notificationThresholdDays): self
    {
        $this->notificationThresholdDays = $notificationThresholdDays;
        return $this;
    }

    public function getAutomaticReturnThresholdDays(): int
    {
        return $this->automaticReturnThresholdDays;
    }

    public function setAutomaticReturnThresholdDays(int $automaticReturnThresholdDays): self
    {
        $this->automaticReturnThresholdDays = $automaticReturnThresholdDays;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function isEnableAutomaticReturns(): bool
    {
        return $this->enableAutomaticReturns;
    }

    public function setEnableAutomaticReturns(bool $enableAutomaticReturns): self
    {
        $this->enableAutomaticReturns = $enableAutomaticReturns;
        return $this;
    }

    public function isEnableNotifications(): bool
    {
        return $this->enableNotifications;
    }

    public function setEnableNotifications(bool $enableNotifications): self
    {
        $this->enableNotifications = $enableNotifications;
        return $this;
    }
}