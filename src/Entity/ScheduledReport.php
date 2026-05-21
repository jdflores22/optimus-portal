<?php

namespace App\Entity;

use App\Entity\Enum\ReportType;
use App\Entity\Enum\ReportFrequency;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduled_reports')]
class ScheduledReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', enumType: ReportType::class)]
    private ReportType $reportType;

    #[ORM\Column(type: 'string', enumType: ReportFrequency::class)]
    private ReportFrequency $frequency;

    #[ORM\Column(type: 'string', length: 10)]
    private string $format; // 'pdf' or 'csv'

    #[ORM\Column(type: 'json')]
    private array $recipients = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $parameters = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $nextRunDate;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastRunDate = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getReportType(): ReportType
    {
        return $this->reportType;
    }

    public function setReportType(ReportType $reportType): self
    {
        $this->reportType = $reportType;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getFrequency(): ReportFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(ReportFrequency $frequency): self
    {
        $this->frequency = $frequency;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    public function setParameters(?array $parameters): self
    {
        $this->parameters = $parameters;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getNextRunDate(): \DateTime
    {
        return $this->nextRunDate;
    }

    public function setNextRunDate(\DateTime $nextRunDate): self
    {
        $this->nextRunDate = $nextRunDate;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getLastRunDate(): ?\DateTime
    {
        return $this->lastRunDate;
    }

    public function setLastRunDate(?\DateTime $lastRunDate): self
    {
        $this->lastRunDate = $lastRunDate;
        $this->updatedAt = new \DateTime();
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

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}