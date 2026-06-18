<?php

namespace App\Entity;

use App\Entity\Enum\AccreditationStatus;
use App\Repository\AccreditationSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccreditationSubmissionRepository::class)]
#[ORM\Table(name: 'accreditation_submissions')]
#[ORM\Index(name: 'idx_accreditation_shipping_line', columns: ['shipping_line_id'])]
#[ORM\UniqueConstraint(name: 'unique_applicant_shipping_line', columns: ['applicant_id', 'shipping_line_id'])]
class AccreditationSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'applicant_id', referencedColumnName: 'id', nullable: false)]
    private User $applicant;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class, inversedBy: 'accreditationSubmissions')]
    #[ORM\JoinColumn(nullable: false)]
    private ShippingLine $shippingLine;

    #[ORM\ManyToOne(targetEntity: FormConfiguration::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'form_configuration_id', referencedColumnName: 'id', nullable: false)]
    private FormConfiguration $formConfig;

    #[ORM\Column(type: 'json')]
    private array $submittedData = [];

    #[ORM\Column(type: 'string', enumType: AccreditationStatus::class)]
    private AccreditationStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'evaluator_id', referencedColumnName: 'id', nullable: true)]
    private ?User $evaluator = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'final_approver_id', referencedColumnName: 'id', nullable: true)]
    private ?User $finalApprover = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $submittedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $evaluatedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $denialReason = null;

    public function __construct()
    {
        $this->submittedAt = new \DateTime();
        $this->status = AccreditationStatus::PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplicant(): User
    {
        return $this->applicant;
    }

    public function setApplicant(User $applicant): self
    {
        $this->applicant = $applicant;
        return $this;
    }

    public function getShippingLine(): ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }

    public function getFormConfig(): FormConfiguration
    {
        return $this->formConfig;
    }

    public function setFormConfig(FormConfiguration $formConfig): self
    {
        $this->formConfig = $formConfig;
        return $this;
    }

    public function getSubmittedData(): array
    {
        return $this->submittedData;
    }

    public function setSubmittedData(array $submittedData): self
    {
        $this->submittedData = $submittedData;
        return $this;
    }

    public function getStatus(): AccreditationStatus
    {
        return $this->status;
    }

    public function setStatus(AccreditationStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getEvaluator(): ?User
    {
        return $this->evaluator;
    }

    public function setEvaluator(?User $evaluator): self
    {
        $this->evaluator = $evaluator;
        return $this;
    }

    public function getFinalApprover(): ?User
    {
        return $this->finalApprover;
    }

    public function setFinalApprover(?User $finalApprover): self
    {
        $this->finalApprover = $finalApprover;
        return $this;
    }

    public function getSubmittedAt(): \DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function getEvaluatedAt(): ?\DateTimeInterface
    {
        return $this->evaluatedAt;
    }

    public function setEvaluatedAt(?\DateTimeInterface $evaluatedAt): self
    {
        $this->evaluatedAt = $evaluatedAt;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): self
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getDenialReason(): ?string
    {
        return $this->denialReason;
    }

    public function setDenialReason(?string $denialReason): self
    {
        $this->denialReason = $denialReason;
        return $this;
    }

    public function isAwaitingFinalApproval(): bool
    {
        return $this->status === AccreditationStatus::AWAITING_FINAL_APPROVAL;
    }

    public function isFullyApproved(): bool
    {
        return $this->status === AccreditationStatus::APPROVED && $this->finalApprover !== null;
    }
}
