<?php

namespace App\Entity;

use App\Repository\UserShippingLinePreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserShippingLinePreferenceRepository::class)]
#[ORM\Table(name: 'user_shipping_line_preferences')]
#[ORM\Index(name: 'idx_user_preferences_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_preferences_shipping_line', columns: ['last_selected_shipping_line_id'])]
#[ORM\UniqueConstraint(name: 'unique_user_preference', columns: ['user_id'])]
class UserShippingLinePreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ShippingLine $lastSelectedShippingLine = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getLastSelectedShippingLine(): ?ShippingLine
    {
        return $this->lastSelectedShippingLine;
    }

    public function setLastSelectedShippingLine(?ShippingLine $shippingLine): self
    {
        $this->lastSelectedShippingLine = $shippingLine;
        $this->updatedAt = new \DateTime();
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
