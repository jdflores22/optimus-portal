<?php

namespace App\Entity;

use App\Entity\Enum\EDOStatus;
use App\Repository\EDOVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * EDO Version History
 * Tracks all versions of an eDO including PDF paths for audit and rollback purposes
 */
#[ORM\Entity(repositoryClass: EDOVersionRepository::class)]
#[ORM\Table(name: 'edo_versions')]
#[ORM\Index(name: 'idx_edo_versions_edo_id', columns: ['edo_id'])]
#[ORM\Index(name: 'idx_edo_versions_current', columns: ['is_current'])]
#[ORM\Index(name: 'idx_edo_versions_created_at', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'unique_edo_version', columns: ['edo_id', 'version_number'])]
class EDOVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ElectronicDeliveryOrder::class)]
    #[ORM\JoinColumn(name: 'edo_id', nullable: false, onDelete: 'CASCADE')]
    private ElectronicDeliveryOrder $edo;

    #[ORM\Column(type: 'integer')]
    private int $versionNumber;

    #[ORM\Column(type: 'string', length: 500)]
    private string $pdfPath;

    #[ORM\Column(type: 'string', length: 50)]
    private string $edoNumber;

    #[ORM\Column(type: 'string', length: 50, enumType: EDOStatus::class)]
    private EDOStatus $status;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $cyLocation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCurrent = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;
        return $this;
    }

    public function getPdfPath(): string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function getEdoNumber(): string
    {
        return $this->edoNumber;
    }

    public function setEdoNumber(string $edoNumber): self
    {
        $this->edoNumber = $edoNumber;
        return $this;
    }

    public function getStatus(): EDOStatus
    {
        return $this->status;
    }

    public function setStatus(EDOStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCyLocation(): ?string
    {
        return $this->cyLocation;
    }

    public function setCyLocation(?string $cyLocation): self
    {
        $this->cyLocation = $cyLocation;
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

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(bool $isCurrent): self
    {
        $this->isCurrent = $isCurrent;
        return $this;
    }
}
