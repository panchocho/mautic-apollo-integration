<?php

namespace Apollo\MauticBundle\Integration;

use Apollo\MauticBundle\Service\SyncService;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Mautic\PluginBundle\Integration\IntegrationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ApolloIntegration extends AbstractIntegration implements IntegrationInterface
{
    public const NAME = 'Apollo';

    private SyncService $syncService;

    public function __construct(
        TranslatorInterface $translator,
        IntegrationHelper $helper,
        SyncService $syncService
    ) {
        parent::__construct($translator, $helper);
        $this->syncService = $syncService;
    }

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
        // API key only for primera versión
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
        // Enables the settings page within the Integrations UI
        return [
            'requires_callback' => false,
        ];
    }

    public function getPublicIdentifier(): ?string
    {
        return null;
    }

    public function persistAuthenticationCredentials(array $credentials): void
    {
        // Let parent handle storage via IntegrationHelper/Config
        parent::persistAuthenticationCredentials($credentials);
    }

    public function isConfigured(): bool
    {
        $keys = $this->getKeys();
        return !empty($keys['api_key']);
    }

    public function sync(): void
    {
        // Exposed for potential manual sync trigger in UI
        $this->syncService->pullFromApollo();
    }
}
