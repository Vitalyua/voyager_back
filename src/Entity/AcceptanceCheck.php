<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'acceptance_check')]
#[ORM\Index(columns: ['waybill_prefix', 'waybill_number'], name: 'idx_acceptance_waybill')]
#[ORM\HasLifecycleCallbacks]
class AcceptanceCheck
{
    public const TYPE_FOH = 'foh';
    public const TYPE_ACCEPT = 'accept';
    public const TYPE_FAILURE = 'failure';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'waybill_prefix', type: Types::STRING, length: 3)]
    private string $waybillPrefix = '';

    #[ORM\Column(name: 'waybill_number', type: Types::STRING, length: 12)]
    private string $waybillNumber = '';

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type = self::TYPE_FOH;

    #[ORM\Column(name: 'foh_confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fohConfirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, FailureReason> */
    #[ORM\OneToMany(targetEntity: FailureReason::class, mappedBy: 'check', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reasons;

    /** @var Collection<int, NotifiedContact> */
    #[ORM\OneToMany(targetEntity: NotifiedContact::class, mappedBy: 'check', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contacts;

    public function __construct()
    {
        $this->reasons = new ArrayCollection();
        $this->contacts = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWaybillPrefix(): string
    {
        return $this->waybillPrefix;
    }

    public function setWaybillPrefix(string $waybillPrefix): self
    {
        $this->waybillPrefix = $waybillPrefix;
        return $this;
    }

    public function getWaybillNumber(): string
    {
        return $this->waybillNumber;
    }

    public function setWaybillNumber(string $waybillNumber): self
    {
        $this->waybillNumber = $waybillNumber;
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

    public function getFohConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->fohConfirmedAt;
    }

    public function setFohConfirmedAt(?\DateTimeImmutable $fohConfirmedAt): self
    {
        $this->fohConfirmedAt = $fohConfirmedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, FailureReason> */
    public function getReasons(): Collection
    {
        return $this->reasons;
    }

    public function addReason(FailureReason $reason): self
    {
        if (!$this->reasons->contains($reason)) {
            $this->reasons->add($reason);
            $reason->setCheck($this);
        }
        return $this;
    }

    public function removeReason(FailureReason $reason): self
    {
        if ($this->reasons->removeElement($reason)) {
            if ($reason->getCheck() === $this) {
                $reason->setCheck(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, NotifiedContact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(NotifiedContact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setCheck($this);
        }
        return $this;
    }

    public function removeContact(NotifiedContact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            if ($contact->getCheck() === $this) {
                $contact->setCheck(null);
            }
        }
        return $this;
    }
}
