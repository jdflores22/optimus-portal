<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'noa_documents')]
#[ORM\Index(name: 'idx_noa_documents_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_noa_documents_noa_number', columns: ['noa_number'])]
class NOADocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Manifest::class, inversedBy: 'noaDocument')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $noaNumber;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $arrivalDate;

    #[ORM\Column(type: 'json')]
    private array $vesselInfo;

    #[ORM\Column(type: 'string', length: 500)]
    private string $pdfPath;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $generatedBy;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNoaNumber(): string
    {
        return $this->noaNumber;
    }

    public function setNoaNumber(string $noaNumber): self
    {
        $this->noaNumber = $noaNumber;
        return $this;
    }

    public function getArrivalDate(): \DateTimeInterface
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(\DateTimeInterface $arrivalDate): self
    {
        $this->arrivalDate = $arrivalDate;
        return $this;
    }

    public function getVesselInfo(): array
    {
        return $this->vesselInfo;
    }

    public function setVesselInfo(array $vesselInfo): self
    {
        $this->vesselInfo = $vesselInfo;
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

    public function getGeneratedBy(): User
    {
        return $this->generatedBy;
    }

    public function setGeneratedBy(User $generatedBy): self
    {
        $this->generatedBy = $generatedBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
