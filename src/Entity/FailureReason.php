<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'failure_reason')]
class FailureReason
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AcceptanceCheck::class, inversedBy: 'reasons')]
    #[ORM\JoinColumn(name: 'check_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?AcceptanceCheck $check = null;

    #[ORM\Column(type: Types::STRING, length: 6)]
    private string $code = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCheck(): ?AcceptanceCheck
    {
        return $this->check;
    }

    public function setCheck(?AcceptanceCheck $check): self
    {
        $this->check = $check;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }
}
