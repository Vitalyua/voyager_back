<?php

// src/Entity/AwbEvent.php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'awb_event')]
#[ORM\Index(columns: ['notification_id'], name: 'idx_awb_event_notification')]
#[ORM\Index(columns: ['code'], name: 'idx_awb_event_code')]
#[ORM\HasLifecycleCallbacks]
class AwbEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: 'awbEvents')]
    #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Notification $notification = null;

    /** Номер лега (0, 1, ...) — на каком отрезке маршрута событие */
    #[ORM\Column(name: 'leg_index', type: Types::INTEGER)]
    private int $legIndex = 0;

    /** IATA-код статуса: FOH, SAC, RCS, MAN, DEP и т.д. */
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(name: 'estimated_time', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $estimatedTime = null;

    #[ORM\Column(name: 'actual_time', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $actualTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $created = null;

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

    public function getNotification(): ?Notification
    {
        return $this->notification;
    }

    public function setNotification(?Notification $n): self
    {
        $this->notification = $n;
        return $this;
    }

    public function getLegIndex(): int
    {
        return $this->legIndex;
    }

    public function setLegIndex(int $v): self
    {
        $this->legIndex = $v;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $v): self
    {
        $this->code = $v;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $v): self
    {
        $this->name = $v;
        return $this;
    }

    public function getEstimatedTime(): ?\DateTimeImmutable
    {
        return $this->estimatedTime;
    }

    public function setEstimatedTime(?\DateTimeImmutable $v): self
    {
        $this->estimatedTime = $v;
        return $this;
    }

    public function getActualTime(): ?\DateTimeImmutable
    {
        return $this->actualTime;
    }

    public function setActualTime(?\DateTimeImmutable $v): self
    {
        $this->actualTime = $v;
        return $this;
    }

    public function getCreated(): ?\DateTimeImmutable
    {
        return $this->created;
    }
}