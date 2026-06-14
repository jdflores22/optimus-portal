<?php

namespace App\Entity;

use App\Entity\Enum\RepositioningRequestStatus;
use App\Entity\Enum\RepositioningRequestType;
use App\Repository\RepositioningRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RepositioningRequestRepository::class)]
#[ORM\Table(name: 'repositioning_requests')]
#[ORM\Index(name: 'idx_rr_shipping_line', columns: ['shipping_line_id'])]
#[ORM\Index(name: 'idx_rr_status', columns: ['status'])]
class RepositioningRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 30, unique: true)]
    private string $requestNumber;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(name: 'shipping_line_id', nullable: false, onDelete: 'CASCADE')]
    private ShippingLine $shippingLine;

    #[ORM\Column(type: 'string', enumType: RepositioningRequestType::class)]
    private RepositioningRequestType $requestType;

    #[ORM\ManyToOne(targetEntity: Terminal::class)]
    #[ORM\JoinColumn(name: 'source_terminal_id', nullable: false, onDelete: 'RESTRICT')]
    private Terminal $sourceTerminal;

    #[ORM\ManyToOne(targetEntity: Terminal::class)]
    #[ORM\JoinColumn(name: 'destination_terminal_id', nullable: false, onDelete: 'RESTRICT')]
    private Terminal $destinationTerminal;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $purpose;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $requestLetter = null;

    #[ORM\Column(type: 'integer')]
    private int $containerCount = 0;

    #[ORM\Column(type: 'string', enumType: RepositioningRequestStatus::class, options: ['default' => 'pending'])]
    private RepositioningRequestStatus $status = RepositioningRequestStatus::PENDING;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $requestedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $requestedBy;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNotes = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    /** @var Collection<int, RepositioningRequestItem> */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: RepositioningRequestItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->requestedAt = new \DateTime();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestNumber(): string
    {
        return $this->requestNumber;
    }

    public function setRequestNumber(string $requestNumber): self
    {
        $this->requestNumber = $requestNumber;

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

    public function getRequestType(): RepositioningRequestType
    {
        return $this->requestType;
    }

    public function setRequestType(RepositioningRequestType $requestType): self
    {
        $this->requestType = $requestType;

        return $this;
    }

    public function getSourceTerminal(): Terminal
    {
        return $this->sourceTerminal;
    }

    public function setSourceTerminal(Terminal $sourceTerminal): self
    {
        $this->sourceTerminal = $sourceTerminal;

        return $this;
    }

    public function getDestinationTerminal(): Terminal
    {
        return $this->destinationTerminal;
    }

    public function setDestinationTerminal(Terminal $destinationTerminal): self
    {
        $this->destinationTerminal = $destinationTerminal;

        return $this;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): self
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function getRequestLetter(): ?string
    {
        return $this->requestLetter;
    }

    public function setRequestLetter(?string $requestLetter): self
    {
        $this->requestLetter = $requestLetter;

        return $this;
    }

    public function getContainerCount(): int
    {
        return $this->containerCount;
    }

    public function setContainerCount(int $containerCount): self
    {
        $this->containerCount = $containerCount;

        return $this;
    }

    public function getStatus(): RepositioningRequestStatus
    {
        return $this->status;
    }

    public function setStatus(RepositioningRequestStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRequestedAt(): \DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(User $requestedBy): self
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getReviewedAt(): ?\DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeInterface $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function setReviewNotes(?string $reviewNotes): self
    {
        $this->reviewNotes = $reviewNotes;

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

    /** @return Collection<int, RepositioningRequestItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(RepositioningRequestItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setRequest($this);
        }

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === RepositioningRequestStatus::PENDING;
    }
}
