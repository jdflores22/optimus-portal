<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'repositioning_request_items')]
class RepositioningRequestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RepositioningRequest::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private RepositioningRequest $request;

    #[ORM\ManyToOne(targetEntity: Container::class)]
    #[ORM\JoinColumn(name: 'container_id', nullable: false, onDelete: 'CASCADE')]
    private Container $container;

    #[ORM\Column(type: 'integer')]
    private int $dwellTimeDays = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dischargeDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): RepositioningRequest
    {
        return $this->request;
    }

    public function setRequest(RepositioningRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function setContainer(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function getDwellTimeDays(): int
    {
        return $this->dwellTimeDays;
    }

    public function setDwellTimeDays(int $dwellTimeDays): self
    {
        $this->dwellTimeDays = $dwellTimeDays;

        return $this;
    }

    public function getDischargeDate(): ?\DateTimeInterface
    {
        return $this->dischargeDate;
    }

    public function setDischargeDate(?\DateTimeInterface $dischargeDate): self
    {
        $this->dischargeDate = $dischargeDate;

        return $this;
    }
}
