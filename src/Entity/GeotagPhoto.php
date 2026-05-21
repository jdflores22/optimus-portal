<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'geotag_photos')]
class GeotagPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: PreAdviceRequest::class, inversedBy: 'geotagPhotos')]
    #[ORM\JoinColumn(nullable: false)]
    private PreAdviceRequest $preAdviceRequest;

    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    #[ORM\Column(type: 'string', length: 255)]
    private string $originalName;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8)]
    private string $longitude;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $capturedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verificationNotes = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $uploadedAt;

    public function __construct()
    {
        $this->uploadedAt = new \DateTime();
        $this->capturedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPreAdviceRequest(): PreAdviceRequest
    {
        return $this->preAdviceRequest;
    }

    public function setPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        $this->preAdviceRequest = $preAdviceRequest;
        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): self
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function getLatitude(): string
    {
        return $this->latitude;
    }

    public function setLatitude(string $latitude): self
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): string
    {
        return $this->longitude;
    }

    public function setLongitude(string $longitude): self
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getCapturedAt(): \DateTime
    {
        return $this->capturedAt;
    }

    public function setCapturedAt(\DateTime $capturedAt): self
    {
        $this->capturedAt = $capturedAt;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerificationNotes(): ?string
    {
        return $this->verificationNotes;
    }

    public function setVerificationNotes(?string $verificationNotes): self
    {
        $this->verificationNotes = $verificationNotes;
        return $this;
    }

    public function getUploadedAt(): \DateTime
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(\DateTime $uploadedAt): self
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }
}