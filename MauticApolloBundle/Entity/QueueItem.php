<?php

namespace MauticPlugin\MauticApolloBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class QueueItem
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    private ?int $id = null;

    private string $type;

    private string $payload;

    private string $status = self::STATUS_PENDING;

    private int $attempts = 0;

    private \DateTimeInterface $createdAt;

    private \DateTimeInterface $nextAttemptAt;

    public function __construct()
    {
        $this->createdAt    = new \DateTimeImmutable();
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('apollo_queue');
        $builder->addId();
        $builder->addField('type', Types::STRING, ['length' => 50]);
        $builder->addField('payload', Types::TEXT);
        $builder->addField('status', Types::STRING, ['length' => 20]);
        $builder->addField('attempts', Types::INTEGER);
        $builder->addField('createdAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'created_at']);
        $builder->addField('nextAttemptAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'next_attempt_at']);
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
