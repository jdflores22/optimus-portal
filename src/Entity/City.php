<?php

namespace App\Entity;

use App\Repository\CityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'cities')]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type; // City or Municipality

    #[ORM\ManyToOne(targetEntity: Region::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: false)]
    private Region $region;

    #[ORM\ManyToOne(targetEntity: Province::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Province $province = null;

    #[ORM\OneToMany(mappedBy: 'city', targetEntity: Barangay::class)]
    private Collection $barangays;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->barangays = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getRegion(): Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getProvince(): ?Province
    {
        return $this->province;
    }

    public function setProvince(?Province $province): self
    {
        $this->province = $province;

        return $this;
    }

    /** @return Collection<int, Barangay> */
    public function getBarangays(): Collection
    {
        return $this->barangays;
    }

    public function addBarangay(Barangay $barangay): self
    {
        if (!$this->barangays->contains($barangay)) {
            $this->barangays[] = $barangay;
            $barangay->setCity($this);
        }

        return $this;
    }

    public function removeBarangay(Barangay $barangay): self
    {
        if ($this->barangays->removeElement($barangay)) {
            if ($barangay->getCity() === $this) {
                $barangay->setCity(null);
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
}
