<?php

namespace App\Entity;

use App\Repository\NOARepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NOARepository::class)]
#[ORM\Table(name: 'noa')]
#[ORM\Index(name: 'idx_noa_noa_number', columns: ['noa_number'])]
#[ORM\Index(name: 'idx_noa_bl_number', columns: ['bl_number'])]
#[ORM\Index(name: 'idx_noa_consignee', columns: ['consignee_id'])]
#[ORM\Index(name: 'idx_noa_created_at', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class NOA
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    #[Assert\NotBlank(message: 'NOA number is required')]
    private string $noaNumber;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'BL number is required')]
    #[Assert\Length(max: 50)]
    private string $blNumber;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Vessel number is required')]
    #[Assert\Length(max: 50)]
    private string $vesselNumber;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotNull(message: 'ETA is required')]
    private \DateTimeInterface $eta;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'CY location is required')]
    #[Assert\Length(max: 100)]
    private string $cyLocation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Consignee is required')]
    private User $consignee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Created by user is required')]
    private User $createdBy;

    #[ORM\OneToMany(mappedBy: 'noa', targetEntity: Container::class, cascade: ['persist'])]
    #[Assert\Count(min: 1, minMessage: 'At least one container is required')]
    private Collection $containers;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $manifestPdfPath = null;

    public function __construct()
    {
        $this->containers = new ArrayCollection();
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

    public function getNoaNumber(): string
    {
        return $this->noaNumber;
    }

    public function setNoaNumber(string $noaNumber): self
    {
        $this->noaNumber = $noaNumber;
        return $this;
    }

    public function getBlNumber(): string
    {
        return $this->blNumber;
    }

    public function setBlNumber(string $blNumber): self
    {
        $this->blNumber = $blNumber;
        return $this;
    }

    public function getVesselNumber(): string
    {
        return $this->vesselNumber;
    }

    public function setVesselNumber(string $vesselNumber): self
    {
        $this->vesselNumber = $vesselNumber;
        return $this;
    }

    public function getEta(): \DateTimeInterface
    {
        return $this->eta;
    }

    public function setEta(\DateTimeInterface $eta): self
    {
        $this->eta = $eta;
        return $this;
    }

    public function getCyLocation(): string
    {
        return $this->cyLocation;
    }

    public function setCyLocation(string $cyLocation): self
    {
        $this->cyLocation = $cyLocation;
        return $this;
    }

    public function getConsignee(): User
    {
        return $this->consignee;
    }

    public function setConsignee(User $consignee): self
    {
        $this->consignee = $consignee;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return Collection<int, Container>
     */
    public function getContainers(): Collection
    {
        return $this->containers;
    }

    public function addContainer(Container $container): self
    {
        if (!$this->containers->contains($container)) {
            $this->containers->add($container);
            $container->setNoa($this);
        }

        return $this;
    }

    public function removeContainer(Container $container): self
    {
        if ($this->containers->removeElement($container)) {
            if ($container->getNoa() === $this) {
                $container->setNoa(null);
            }
        }

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

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): self
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function getManifestPdfPath(): ?string
    {
        return $this->manifestPdfPath;
    }

    public function setManifestPdfPath(?string $manifestPdfPath): self
    {
        $this->manifestPdfPath = $manifestPdfPath;
        return $this;
    }

    /**
     * Generate NOA number with format: NOA-YYYYMMDD-XXXX
     */
    public static function generateNoaNumber(int $sequence): string
    {
        $date = (new \DateTime())->format('Ymd');
        $sequenceStr = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        return sprintf('NOA-%s-%s', $date, $sequenceStr);
    }
}
