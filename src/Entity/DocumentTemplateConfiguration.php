<?php

namespace App\Entity;

use App\Entity\Enum\DocumentTemplateType;
use App\Entity\Enum\FormStatus;
use App\Form\DocumentBlockTypes;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_template_configurations')]
#[ORM\Index(name: 'idx_doc_template_type_status', columns: ['document_type', 'status'])]
class DocumentTemplateConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', enumType: DocumentTemplateType::class)]
    private DocumentTemplateType $documentType;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'string', enumType: FormStatus::class)]
    private FormStatus $status;

    #[ORM\Column(type: 'json')]
    private array $layout = [];

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'A4'])]
    private string $paperSize = 'A4';

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'portrait'])]
    private string $orientation = 'portrait';

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = FormStatus::DRAFT;
        $this->version = 1;
    }

    public function getId(): ?int
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function incrementVersion(): self
    {
        $this->version++;
        return $this;
    }

    public function getStatus(): FormStatus
    {
        return $this->status;
    }

    public function setStatus(FormStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getLayout(): array
    {
        return $this->layout;
    }

    public function setLayout(array $layout): self
    {
        $this->validateLayoutStructure($layout);
        $this->layout = $layout;
        return $this;
    }

    public function getPaperSize(): string
    {
        return $this->paperSize;
    }

    public function setPaperSize(string $paperSize): self
    {
        $this->paperSize = $paperSize;
        return $this;
    }

    public function getOrientation(): string
    {
        return $this->orientation;
    }

    public function setOrientation(string $orientation): self
    {
        $this->orientation = $orientation;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeInterface $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
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

    public function publish(): self
    {
        $this->status = FormStatus::PUBLISHED;
        $this->publishedAt = new \DateTime();
        return $this;
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [FormStatus::PUBLISHED, FormStatus::ACTIVE, FormStatus::INACTIVE], true);
    }

    public function isActive(): bool
    {
        return $this->status === FormStatus::ACTIVE;
    }

    public function activate(): self
    {
        $this->status = FormStatus::ACTIVE;
        return $this;
    }

    public function deactivate(): self
    {
        $this->status = FormStatus::INACTIVE;
        return $this;
    }

    public function unpublish(): self
    {
        $this->status = FormStatus::DRAFT;
        return $this;
    }

    public function isDeletable(): bool
    {
        return in_array($this->status, [FormStatus::DRAFT, FormStatus::INACTIVE], true);
    }

    public function getElements(): array
    {
        return $this->layout['elements'] ?? [];
    }

    private function validateLayoutStructure(array $layout): void
    {
        if (!isset($layout['canvas']) || !is_array($layout['canvas'])) {
            throw new \InvalidArgumentException('Layout must contain a "canvas" object');
        }

        if (!isset($layout['elements']) || !is_array($layout['elements'])) {
            throw new \InvalidArgumentException('Layout must contain an "elements" array');
        }

        foreach ($layout['elements'] as $element) {
            if (!is_array($element)) {
                throw new \InvalidArgumentException('Each element must be an array');
            }

            if (!isset($element['id'], $element['type'], $element['order'])) {
                throw new \InvalidArgumentException('Each element must have id, type, and order');
            }

            if (!in_array($element['type'], DocumentBlockTypes::ALL, true)) {
                throw new \InvalidArgumentException(sprintf('Invalid element type: %s', $element['type']));
            }
        }
    }
}
