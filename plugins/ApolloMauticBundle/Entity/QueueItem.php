<?php

namespace Mautic\ApolloBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="apollo_queue")
 */
class QueueItem
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $type;

    /**
     * @ORM\Column(type="text")
     */
    private string $payload;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $status = self::STATUS_PENDING;

    /**
     * @ORM\Column(type="integer")
     */
    private int $attempts = 0;

    /**
     * @ORM\Column(type="datetime")
     */
    private \DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private \DateTimeInterface $nextAttemptAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getNextAttemptAt(): \DateTimeInterface
    {
        return $this->nextAttemptAt;
    }

    public function setNextAttemptAt(\DateTimeInterface $dt): void
    {
        $this->nextAttemptAt = $dt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
