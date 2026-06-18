<?php

namespace App\Entity;

use App\Entity\Enum\DocumentTemplateType;
use App\Repository\DocumentVerificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentVerificationRepository::class)]
#[ORM\Table(name: 'document_verifications')]
#[ORM\UniqueConstraint(name: 'uniq_document_verification_token', columns: ['verification_token'])]
#[ORM\UniqueConstraint(name: 'uniq_document_verification_subject', columns: ['document_type', 'subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_document_verification_token', columns: ['verification_token'])]
#[ORM\HasLifecycleCallbacks]
class DocumentVerification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $verificationToken;

    #[ORM\Column(type: 'string', length: 32, enumType: DocumentTemplateType::class)]
    private DocumentTemplateType $documentType;

    #[ORM\Column(type: 'string', length: 32)]
    private string $subjectType;

    #[ORM\Column(type: 'integer')]
    private int $subjectId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $documentNumber;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVerificationToken(): string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(string $verificationToken): self
    {
        $this->verificationToken = $verificationToken;

        return $this;
    }

    public function getDocumentType(): DocumentTemplateType
    {
        return $this->documentType;
    }

    public function setDocumentType(DocumentTemplateType $documentType): self
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function setSubjectType(string $subjectType): self
    {
        $this->subjectType = $subjectType;

        return $this;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function setSubjectId(int $subjectId): self
    {
        $this->subjectId = $subjectId;

        return $this;
    }

    public function getDocumentNumber(): string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSummary(): ?array
    {
        return $this->summary;
    }

    /**
     * @param array<string, mixed>|null $summary
     */
    public function setSummary(?array $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
