<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['logistic_object_type', 'logistic_object_id'], name: 'idx_logistic_object')]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;
    #[ORM\OneToMany(mappedBy: 'notification', targetEntity: AwbEvent::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['legIndex' => 'ASC', 'estimatedTime' => 'ASC'])]
    private Collection $awbEvents;

    #[ORM\OneToMany(mappedBy: 'notification', targetEntity: FailureReason::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reasons;

    #[ORM\OneToMany(mappedBy: 'notification', targetEntity: NotifiedContact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contacts;
    #[ORM\Column(type: Types::JSON)]
    private array $json = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $created = null;

    #[ORM\Column(name: 'logistic_object_id', type: Types::STRING, nullable: true)]
    private ?string $logisticObjectId = null;

    #[ORM\Column(name: 'logistic_object_type', type: Types::STRING, length: 100, nullable: true)]
    private ?string $logisticObjectType = null;
    #[ORM\Column(name: 'waybill_prefix', type: Types::STRING, length: 10, nullable: true)]
    private ?string $waybillPrefix = null;

    #[ORM\Column(name: 'waybill_number', type: Types::STRING, length: 20, nullable: true)]
    private ?string $waybillNumber = null;

    #[ORM\Column(name: 'flights', type: Types::JSON, nullable: true)]
    private ?array $flights = null;

    #[ORM\Column(name: 'roadmap', type: Types::JSON, nullable: true)]
    private ?array $roadmap = null;

    #[ORM\Column(name: 'commodity', type: Types::STRING, length: 10, nullable: true)]
    private ?string $commodity = null;

    public function getCommodity(): ?string { return $this->commodity; }
    public function setCommodity(?string $commodity): self { $this->commodity = $commodity; return $this; }

    public function __construct()
    {
        $this->awbEvents = new ArrayCollection();
        $this->reasons   = new ArrayCollection();
        $this->contacts  = new ArrayCollection();
    }

    /** @return Collection<int, AwbEvent> */
    public function getAwbEvents(): Collection { return $this->awbEvents; }

    public function addAwbEvent(AwbEvent $e): self
    {
        if (!$this->awbEvents->contains($e)) {
            $this->awbEvents->add($e);
            $e->setNotification($this);
        }
        return $this;
    }

    public function getReasons(): Collection { return $this->reasons; }

    public function addReason(FailureReason $r): self
    {
        if (!$this->reasons->contains($r)) {
            $this->reasons->add($r);
            $r->setNotification($this);
        }
        return $this;
    }

    /** @return Collection<int, NotifiedContact> */
    public function getContacts(): Collection { return $this->contacts; }

    public function addContact(NotifiedContact $c): self
    {
        if (!$this->contacts->contains($c)) {
            $this->contacts->add($c);
            $c->setNotification($this);
        }
        return $this;
    }
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->created === null) {
            $this->created = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJson(): array
    {
        return $this->json;
    }

    public function setJson(array $json): self
    {
        $this->json = $json;
        return $this;
    }

    public function getCreated(): ?\DateTimeImmutable
    {
        return $this->created;
    }

    public function setCreated(\DateTimeImmutable $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getLogisticObjectId(): ?string
    {
        return $this->logisticObjectId;
    }

    public function setLogisticObjectId($logisticObjectId): self
    {
        $this->logisticObjectId = $logisticObjectId;
        return $this;
    }

    public function getLogisticObjectType(): ?string
    {
        return $this->logisticObjectType;
    }

    public function setLogisticObjectType(?string $logisticObjectType): self
    {
        $this->logisticObjectType = $logisticObjectType;
        return $this;
    }
    public function getWaybillPrefix(): ?string { return $this->waybillPrefix; }
    public function setWaybillPrefix(?string $v): self { $this->waybillPrefix = $v; return $this; }

    public function getWaybillNumber(): ?string { return $this->waybillNumber; }
    public function setWaybillNumber(?string $v): self { $this->waybillNumber = $v; return $this; }

    public function getFlights(): ?array { return $this->flights; }
    public function setFlights(?array $flights): self { $this->flights = $flights; return $this; }

    public function getRoadmap(): ?array { return $this->roadmap; }
    public function setRoadmap(?array $roadmap): self { $this->roadmap = $roadmap; return $this; }
}