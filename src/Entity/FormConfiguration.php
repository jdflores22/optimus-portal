<?php

namespace App\Entity;

use App\Entity\Enum\FormStatus;
use App\Entity\Enum\FormType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'form_configurations')]
class FormConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', enumType: FormType::class)]
    private FormType $type;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'string', enumType: FormStatus::class)]
    private FormStatus $status;

    #[ORM\Column(type: 'json')]
    private array $fields = [];

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $publishedAt = null;

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

    public function getType(): FormType
    {
        return $this->type;
    }

    public function setType(FormType $type): self
    {
        $this->type = $type;
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

    public function getFields(): array
    {
        return $this->fields;
    }

    public function setFields(array $fields): self
    {
        $this->validateFieldsStructure($fields);
        $this->fields = $fields;
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

    public function publish(): self
    {
        $this->status = FormStatus::PUBLISHED;
        $this->publishedAt = new \DateTime();
        return $this;
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [FormStatus::PUBLISHED, FormStatus::ACTIVE, FormStatus::INACTIVE]);
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
        // Can delete DRAFT and INACTIVE forms (but still need to check for submissions)
        return in_array($this->status, [FormStatus::DRAFT, FormStatus::INACTIVE]);
    }

    /**
     * Validates the JSON structure of fields array
     * Expected structure:
     * [
     *   "fields" => [
     *     [
     *       "id" => "field_uuid",
     *       "label" => "Field Label",
     *       "type" => "text|number|date|file|dropdown|checkbox|radio|geolocation",
     *       "required" => true|false,
     *       "validation" => [...],
     *       "order" => 1
     *     ]
     *   ]
     * ]
     */
    private function validateFieldsStructure(array $fields): void
    {
        if (!isset($fields['fields']) || !is_array($fields['fields'])) {
            throw new \InvalidArgumentException('Fields must contain a "fields" array');
        }

        $allowedTypes = \App\Form\FormFieldTypes::ALL;

        foreach ($fields['fields'] as $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException('Each field must be an array');
            }

            if (!isset($field['id']) || !is_string($field['id'])) {
                throw new \InvalidArgumentException('Each field must have a string "id"');
            }

            if (!isset($field['label']) || !is_string($field['label'])) {
                throw new \InvalidArgumentException('Each field must have a string "label"');
            }

            if (!isset($field['type']) || !in_array($field['type'], $allowedTypes)) {
                throw new \InvalidArgumentException('Each field must have a valid "type"');
            }

            if (!isset($field['required']) || !is_bool($field['required'])) {
                throw new \InvalidArgumentException('Each field must have a boolean "required"');
            }

            if (!isset($field['order']) || !is_int($field['order'])) {
                throw new \InvalidArgumentException('Each field must have an integer "order"');
            }
        }
    }
}
