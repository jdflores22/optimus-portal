<?php

namespace App\Entity;

use App\Entity\Enum\TerminalIdentity;
use App\Entity\Enum\TerminalType;
use App\Repository\TerminalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TerminalRepository::class)]
#[ORM\Table(name: 'terminals')]
class Terminal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', enumType: TerminalIdentity::class)]
    private TerminalIdentity $identity;

    #[ORM\Column(type: 'string', enumType: TerminalType::class)]
    private TerminalType $type;

    #[ORM\Column(type: 'string', length: 255)]
    private string $location;

    #[ORM\ManyToOne(targetEntity: Region::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Region $region = null;

    #[ORM\ManyToOne(targetEntity: City::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?City $city = null;

    #[ORM\Column(type: 'integer')]
    private int $dailyCapacity;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\OneToMany(mappedBy: 'terminal', targetEntity: TerminalSlot::class)]
    private Collection $slots;

    #[ORM\OneToMany(mappedBy: 'selectedTerminal', targetEntity: PreAdviceRequest::class)]
    private Collection $preAdviceRequests;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->slots = new ArrayCollection();
        $this->preAdviceRequests = new ArrayCollection();
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
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getType(): TerminalType
    {
        return $this->type;
    }

    public function setType(TerminalType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getIdentity(): TerminalIdentity
    {
        return $this->identity;
    }

    public function setIdentity(TerminalIdentity $identity): self
    {
        $this->identity = $identity;
        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): self
    {
        $this->location = $location;
        return $this;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(?City $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getDailyCapacity(): int
    {
        return $this->dailyCapacity;
    }

    public function setDailyCapacity(int $dailyCapacity): self
    {
        $this->dailyCapacity = $dailyCapacity;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(TerminalSlot $slot): self
    {
        if (!$this->slots->contains($slot)) {
            $this->slots[] = $slot;
            $slot->setTerminal($this);
        }

        return $this;
    }

    public function removeSlot(TerminalSlot $slot): self
    {
        if ($this->slots->removeElement($slot)) {
            if ($slot->getTerminal() === $this) {
                $slot->setTerminal(null);
            }
        }

        return $this;
    }

    public function getPreAdviceRequests(): Collection
    {
        return $this->preAdviceRequests;
    }

    public function addPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if (!$this->preAdviceRequests->contains($preAdviceRequest)) {
            $this->preAdviceRequests[] = $preAdviceRequest;
            $preAdviceRequest->setSelectedTerminal($this);
        }

        return $this;
    }

    public function removePreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if ($this->preAdviceRequests->removeElement($preAdviceRequest)) {
            if ($preAdviceRequest->getSelectedTerminal() === $this) {
                $preAdviceRequest->setSelectedTerminal(null);
            }
        }

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