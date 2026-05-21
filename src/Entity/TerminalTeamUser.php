<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'terminal_team_users')]
class TerminalTeamUser extends User
{
    #[ORM\Column(type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $department;

    #[ORM\Column(type: 'json')]
    private array $terminalPermissions = [];

    #[ORM\OneToMany(targetEntity: PreAdviceRequest::class, mappedBy: 'verifiedBy')]
    private Collection $verifiedPreAdviceRequests;

    public function __construct()
    {
        parent::__construct();
        $this->verifiedPreAdviceRequests = new ArrayCollection();
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getDepartment(): string
    {
        return $this->department;
    }

    public function setDepartment(string $department): self
    {
        $this->department = $department;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getTerminalPermissions(): array
    {
        return $this->terminalPermissions;
    }

    public function setTerminalPermissions(array $terminalPermissions): self
    {
        $this->terminalPermissions = $terminalPermissions;
        return $this;
    }

    public function hasTerminalPermission(string $permission): bool
    {
        return in_array($permission, $this->terminalPermissions, true);
    }

    public function addTerminalPermission(string $permission): self
    {
        if (!$this->hasTerminalPermission($permission)) {
            $this->terminalPermissions[] = $permission;
        }
        return $this;
    }

    public function removeTerminalPermission(string $permission): self
    {
        $key = array_search($permission, $this->terminalPermissions, true);
        if ($key !== false) {
            unset($this->terminalPermissions[$key]);
            $this->terminalPermissions = array_values($this->terminalPermissions);
        }
        return $this;
    }

    /**
     * @return Collection<int, PreAdviceRequest>
     */
    public function getVerifiedPreAdviceRequests(): Collection
    {
        return $this->verifiedPreAdviceRequests;
    }

    public function addVerifiedPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if (!$this->verifiedPreAdviceRequests->contains($preAdviceRequest)) {
            $this->verifiedPreAdviceRequests->add($preAdviceRequest);
            $preAdviceRequest->setVerifiedBy($this);
        }

        return $this;
    }

    public function removeVerifiedPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if ($this->verifiedPreAdviceRequests->removeElement($preAdviceRequest)) {
            if ($preAdviceRequest->getVerifiedBy() === $this) {
                $preAdviceRequest->setVerifiedBy(null);
            }
        }

        return $this;
    }
}