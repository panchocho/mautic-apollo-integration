<?php

namespace MauticPlugin\MauticApolloBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class SyncState
{
    private ?int $id = null;

    private ?\DateTimeInterface $lastContactsSyncAt = null;

    private ?\DateTimeInterface $lastAccountsSyncAt = null;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('apollo_sync_state');
        $builder->addId();
        $builder->addNullableField('lastContactsSyncAt', Types::DATETIME_MUTABLE, 'last_contacts_sync_at');
        $builder->addNullableField('lastAccountsSyncAt', Types::DATETIME_MUTABLE, 'last_accounts_sync_at');
    }

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
