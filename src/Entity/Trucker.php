<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'truckers')]
class Trucker extends User
{
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $truckPlateNumber = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $apiTokenHash = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $apiTokenExpiresAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastActivityAt = null;

    #[ORM\OneToMany(targetEntity: PreAdviceRequest::class, mappedBy: 'trucker')]
    private Collection $preAdviceRequests;

    public function __construct()
    {
        parent::__construct();
        $this->preAdviceRequests = new ArrayCollection();
    }

    public function getFirstName(): string
    {
        return $this->firstName ?? '';
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName ?? '';
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        $firstName = $this->firstName ?? '';
        $lastName = $this->lastName ?? '';
        return trim($firstName . ' ' . $lastName) ?: 'Unknown';
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(?string $licenseNumber): self
    {
        $this->licenseNumber = $licenseNumber;
        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): self
    {
        $this->companyName = $companyName;
        return $this;
    }

    public function getTruckPlateNumber(): ?string
    {
        return $this->truckPlateNumber;
    }

    public function setTruckPlateNumber(?string $truckPlateNumber): self
    {
        $this->truckPlateNumber = $truckPlateNumber;
        return $this;
    }

    /**
     * @return Collection<int, PreAdviceRequest>
     */
    public function getPreAdviceRequests(): Collection
    {
        return $this->preAdviceRequests;
    }

    public function addPreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if (!$this->preAdviceRequests->contains($preAdviceRequest)) {
            $this->preAdviceRequests->add($preAdviceRequest);
            $preAdviceRequest->setTrucker($this);
        }

        return $this;
    }

    public function removePreAdviceRequest(PreAdviceRequest $preAdviceRequest): self
    {
        if ($this->preAdviceRequests->removeElement($preAdviceRequest)) {
            if ($preAdviceRequest->getTrucker() === $this) {
                $preAdviceRequest->setTrucker(null);
            }
        }

        return $this;
    }

    public function getApiTokenHash(): ?string
    {
        return $this->apiTokenHash;
    }

    public function setApiTokenHash(?string $apiTokenHash): self
    {
        $this->apiTokenHash = $apiTokenHash;
        return $this;
    }

    public function getApiTokenExpiresAt(): ?\DateTime
    {
        return $this->apiTokenExpiresAt;
    }

    public function setApiTokenExpiresAt(?\DateTime $apiTokenExpiresAt): self
    {
        $this->apiTokenExpiresAt = $apiTokenExpiresAt;
        return $this;
    }

    public function getLastActivityAt(): ?\DateTime
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTime $lastActivityAt): self
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    /**
     * Generate a new API token for this trucker
     */
    public function generateApiToken(int $validityDays = 30): string
    {
        // Generate a secure random token
        $token = bin2hex(random_bytes(32));
        
        // Store the hash of the token
        $this->apiTokenHash = hash('sha256', $token);
        
        // Set expiration date
        $this->apiTokenExpiresAt = new \DateTime("+{$validityDays} days");
        
        return $token;
    }

    /**
     * Revoke the current API token
     */
    public function revokeApiToken(): self
    {
        $this->apiTokenHash = null;
        $this->apiTokenExpiresAt = null;
        return $this;
    }

    /**
     * Check if API token is valid and not expired
     */
    public function hasValidApiToken(): bool
    {
        if (!$this->apiTokenHash) {
            return false;
        }

        if ($this->apiTokenExpiresAt && $this->apiTokenExpiresAt < new \DateTime()) {
            return false;
        }

        return true;
    }
}