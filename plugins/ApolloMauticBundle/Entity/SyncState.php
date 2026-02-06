<?php

namespace Apollo\MauticBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="apollo_sync_state")
 */
class SyncState
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $lastContactsSyncAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $lastAccountsSyncAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastContactsSyncAt(): ?\DateTimeInterface
    {
        return $this->lastContactsSyncAt;
    }

    public function setLastContactsSyncAt(?\DateTimeInterface $dt): void
    {
        $this->lastContactsSyncAt = $dt;
    }

    public function getLastAccountsSyncAt(): ?\DateTimeInterface
    {
        return $this->lastAccountsSyncAt;
    }

    public function setLastAccountsSyncAt(?\DateTimeInterface $dt): void
    {
        $this->lastAccountsSyncAt = $dt;
    }
}
