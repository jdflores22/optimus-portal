<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_state_history')]
#[ORM\Index(name: 'idx_workflow_history_manifest', columns: ['manifest_id'])]
#[ORM\Index(name: 'idx_workflow_history_created_at', columns: ['created_at'])]
class WorkflowStateHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Manifest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Manifest $manifest;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $fromState = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $toState;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(type: 'string', length: 50)]
    private string $actorRole;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $transitionReason = null;

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

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function setManifest(Manifest $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
    }

    public function getFromState(): ?string
    {
        return $this->fromState;
    }

    public function setFromState(?string $fromState): self
    {
        $this->fromState = $fromState;
        return $this;
    }

    public function getToState(): string
    {
        return $this->toState;
    }

    public function setToState(string $toState): self
    {
        $this->toState = $toState;
        return $this;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function setActor(User $actor): self
    {
        $this->actor = $actor;
        return $this;
    }

    public function getActorRole(): string
    {
        return $this->actorRole;
    }

    public function setActorRole(string $actorRole): self
    {
        $this->actorRole = $actorRole;
        return $this;
    }

    public function getTransitionReason(): ?string
    {
        return $this->transitionReason;
    }

    public function setTransitionReason(?string $transitionReason): self
    {
        $this->transitionReason = $transitionReason;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
