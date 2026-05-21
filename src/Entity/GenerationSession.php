<?php

namespace App\Entity;

use App\Repository\GenerationSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GenerationSessionRepository::class)]
#[ORM\Table(name: 'edo_generation_sessions')]
#[ORM\Index(name: 'idx_session_id', columns: ['session_id'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class GenerationSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[Assert\NotBlank(message: 'Session ID is required')]
    #[Assert\Length(max: 36)]
    private string $sessionId;

    #[ORM\ManyToOne(targetEntity: Manifest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Manifest is required')]
    private Manifest $manifest;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Initiated by user is required')]
    private User $initiatedBy;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(choices: ['in_progress', 'completed', 'cancelled', 'failed'])]
    private string $status;

    #[ORM\Column(type: 'integer')]
    #[Assert\NotNull(message: 'Total containers is required')]
    #[Assert\PositiveOrZero]
    private int $totalContainers;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $completedContainers = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $failedContainers = 0;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $currentContainer = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotNull(message: 'Expiration date is required')]
    private \DateTimeInterface $expirationDate;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $failures = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'manifest'])]
    #[Assert\NotBlank(message: 'Document type is required')]
    #[Assert\Choice(choices: ['manifest', 'noa', 'bl'])]
    private string $documentType = 'manifest';

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $documentNumber = null;

    public function __construct()
    {
        $this->startedAt = new \DateTime();
        $this->status = 'in_progress';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function setManifest(Manifest $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
    }

    public function getInitiatedBy(): User
    {
        return $this->initiatedBy;
    }

    public function setInitiatedBy(User $initiatedBy): self
    {
        $this->initiatedBy = $initiatedBy;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTotalContainers(): int
    {
        return $this->totalContainers;
    }

    public function setTotalContainers(int $totalContainers): self
    {
        $this->totalContainers = $totalContainers;
        return $this;
    }

    public function getCompletedContainers(): int
    {
        return $this->completedContainers;
    }

    public function setCompletedContainers(int $completedContainers): self
    {
        $this->completedContainers = $completedContainers;
        return $this;
    }

    public function getFailedContainers(): int
    {
        return $this->failedContainers;
    }

    public function setFailedContainers(int $failedContainers): self
    {
        $this->failedContainers = $failedContainers;
        return $this;
    }

    public function getCurrentContainer(): ?string
    {
        return $this->currentContainer;
    }

    public function setCurrentContainer(?string $currentContainer): self
    {
        $this->currentContainer = $currentContainer;
        return $this;
    }

    public function getExpirationDate(): \DateTimeInterface
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(\DateTimeInterface $expirationDate): self
    {
        $this->expirationDate = $expirationDate;
        return $this;
    }

    public function getFailures(): ?array
    {
        return $this->failures;
    }

    public function setFailures(?array $failures): self
    {
        $this->failures = $failures;
        return $this;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeInterface $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function setCancelledBy(?User $cancelledBy): self
    {
        $this->cancelledBy = $cancelledBy;
        return $this;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function setDocumentType(string $documentType): self
    {
        $this->documentType = $documentType;
        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;
        return $this;
    }
}
