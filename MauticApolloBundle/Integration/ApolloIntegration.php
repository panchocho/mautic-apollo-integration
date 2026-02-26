<?php

namespace MauticPlugin\MauticApolloBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class ApolloIntegration extends AbstractIntegration
{
    public const NAME = 'Apollo';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Apollo.io';
    }

    public function getAuthenticationType(): string
    {
        // API key only
        return 'api_key';
    }

    public function getRequiredKeyFields(): array
    {
        return [
            'api_key' => 'API Key',
        ];
    }

    public function getFormSettings(): array
    {
        return [
            'requires_callback' => false,
        ];
    }

    public function getPublicIdentifier(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        $keys = $this->getKeys();
        return !empty($keys['api_key']);
    }
}
