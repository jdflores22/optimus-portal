<?php

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Repository\PendingUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PendingUserRepository::class)]
#[ORM\Table(name: 'pending_users')]
#[ORM\Index(columns: ['acceptance_token'], name: 'idx_pending_users_token')]
#[ORM\Index(columns: ['email'], name: 'idx_pending_users_email')]
#[ORM\Index(columns: ['created_by_admin_id'], name: 'idx_pending_users_admin')]
#[ORM\Index(columns: ['status'], name: 'idx_pending_users_status')]
#[ORM\Index(columns: ['token_expires_at'], name: 'idx_pending_users_expires')]
#[ORM\UniqueConstraint(name: 'UNIQ_pending_users_token', columns: ['acceptance_token'])]
#[ORM\HasLifecycleCallbacks]
class PendingUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Please enter a valid email address')]
    #[Assert\Length(max: 180, maxMessage: 'Email cannot be longer than {{ limit }} characters')]
    private string $email;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'First name is required')]
    #[Assert\Length(
        min: 1,
        max: 100,
        minMessage: 'First name must be at least {{ limit }} character long',
        maxMessage: 'First name cannot be longer than {{ limit }} characters'
    )]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'Last name is required')]
    #[Assert\Length(
        min: 1,
        max: 100,
        minMessage: 'Last name must be at least {{ limit }} character long',
        maxMessage: 'Last name cannot be longer than {{ limit }} characters'
    )]
    private string $lastName;

    #[ORM\Column(type: 'string', enumType: UserRole::class)]
    #[Assert\NotNull(message: 'Role is required')]
    private UserRole $role;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    #[Assert\NotBlank(message: 'Acceptance token is required')]
    #[Assert\Length(exactly: 64, exactMessage: 'Acceptance token must be exactly {{ limit }} characters')]
    private string $acceptanceToken;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotNull(message: 'Token expiration date is required')]
    private \DateTimeInterface $tokenExpiresAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_admin_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Creating admin is required')]
    private User $createdByAdmin;

    #[ORM\ManyToOne(targetEntity: ShippingLine::class)]
    #[ORM\JoinColumn(name: 'shipping_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ShippingLine $shippingLine = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'shipping_line_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $shippingLineAdmin = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'pending'])]
    #[Assert\Choice(
        choices: ['pending', 'expired', 'accepted', 'declined', 'temporarily_disabled'],
        message: 'Status must be one of: pending, expired, accepted, declined, temporarily_disabled'
    )]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $disabledUntil = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
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

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getAcceptanceToken(): string
    {
        return $this->acceptanceToken;
    }

    public function setAcceptanceToken(string $acceptanceToken): self
    {
        $this->acceptanceToken = $acceptanceToken;
        return $this;
    }

    public function getTokenExpiresAt(): \DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(\DateTimeInterface $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        return $this;
    }

    public function getCreatedByAdmin(): User
    {
        return $this->createdByAdmin;
    }

    public function setCreatedByAdmin(User $createdByAdmin): self
    {
        $this->createdByAdmin = $createdByAdmin;
        return $this;
    }

    public function getShippingLine(): ?ShippingLine
    {
        return $this->shippingLine;
    }

    public function setShippingLine(?ShippingLine $shippingLine): self
    {
        $this->shippingLine = $shippingLine;
        return $this;
    }

    public function getShippingLineAdmin(): ?User
    {
        return $this->shippingLineAdmin;
    }

    public function setShippingLineAdmin(?User $shippingLineAdmin): self
    {
        $this->shippingLineAdmin = $shippingLineAdmin;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getDisabledUntil(): ?\DateTimeInterface
    {
        return $this->disabledUntil;
    }

    public function setDisabledUntil(?\DateTimeInterface $disabledUntil): self
    {
        $this->disabledUntil = $disabledUntil;
        return $this;
    }

    // Business Logic Methods

    /**
     * Checks if the acceptance token is still valid (not expired)
     */
    public function isTokenValid(): bool
    {
        return $this->tokenExpiresAt > new \DateTime() && $this->status === 'pending';
    }

    /**
     * Checks if the pending user is expired
     */
    public function isExpired(): bool
    {
        return $this->tokenExpiresAt <= new \DateTime() || $this->status === 'expired';
    }

    /**
     * Marks the pending user as expired
     */
    public function markAsExpired(): self
    {
        $this->status = 'expired';
        return $this;
    }

    /**
     * Marks the pending user as accepted
     */
    public function markAsAccepted(): self
    {
        $this->status = 'accepted';
        return $this;
    }

    /**
     * Marks the pending user as declined
     */
    public function markAsDeclined(): self
    {
        $this->status = 'declined';
        return $this;
    }

    /**
     * Checks if the pending user can be processed (accepted/declined)
     */
    public function canBeProcessed(): bool
    {
        // Check if temporarily disabled and still within disable period
        if ($this->status === 'temporarily_disabled' && $this->disabledUntil && $this->disabledUntil > new \DateTime()) {
            return false;
        }
        
        return $this->isTokenValid() && in_array($this->status, ['pending', 'temporarily_disabled']);
    }

    /**
     * Generates a secure acceptance token
     */
    public function generateAcceptanceToken(): self
    {
        $this->acceptanceToken = bin2hex(random_bytes(32)); // 64 character hex string
        return $this;
    }

    /**
     * Sets the token expiration to 7 days from now
     */
    public function setTokenExpirationToSevenDays(): self
    {
        $this->tokenExpiresAt = (new \DateTime())->add(new \DateInterval('P7D'));
        return $this;
    }

    /**
     * Validates the pending user data
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->email)) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address';
        }

        if (empty($this->firstName)) {
            $errors[] = 'First name is required';
        }

        if (empty($this->lastName)) {
            $errors[] = 'Last name is required';
        }

        if (!isset($this->role)) {
            $errors[] = 'Role is required';
        }

        if (empty($this->acceptanceToken)) {
            $errors[] = 'Acceptance token is required';
        } elseif (strlen($this->acceptanceToken) !== 64) {
            $errors[] = 'Acceptance token must be exactly 64 characters';
        }

        if (!isset($this->tokenExpiresAt)) {
            $errors[] = 'Token expiration date is required';
        }

        if (!isset($this->createdByAdmin)) {
            $errors[] = 'Creating admin is required';
        }

        return $errors;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    public function __toString(): string
    {
        return $this->getFullName() . ' (' . $this->email . ')';
    }
}