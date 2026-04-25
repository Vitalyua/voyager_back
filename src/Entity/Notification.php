<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

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

    #[ORM\Column(type: Types::JSON)]
    private array $json = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $created = null;

    #[ORM\Column(name: 'logistic_object_id', type: Types::STRING, nullable: true)]
    private ?string $logisticObjectId = null;

    #[ORM\Column(name: 'logistic_object_type', type: Types::STRING, length: 100, nullable: true)]
    private ?string $logisticObjectType = null;

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
}