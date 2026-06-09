<?php

namespace MauticPlugin\MauticApolloBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticApolloBundle\Exception\ApolloQuotaExceededException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ApolloApiClient
{
    private IntegrationHelper $integrationHelper;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    private const BASE_URI = 'https://api.apollo.io/v1';

    public function __construct(
        IntegrationHelper $integrationHelper,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->httpClient        = $httpClient;
        $this->logger            = $logger;
    }

    public function isConfigured(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration) {
            return false;
        }
        $keys = $integration->getKeys();
        return !empty($keys['api_key']);
    }

    private function getApiKey(): ?string
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        $keys        = $integration ? $integration->getKeys() : [];
        return $keys['api_key'] ?? null;
    }

    public function searchContacts(array $params): array
    {
        return $this->request('POST', '/contacts/search', $params);
    }

    public function upsertContact(array $payload): array
    {
        // Apollo lacks true upsert; we try update when id present else create
        $path = isset($payload['id']) ? '/contacts/' . $payload['id'] : '/contacts';
        $method = isset($payload['id']) ? 'PUT' : 'POST';
        return $this->request($method, $path, $payload);
    }

    public function request(string $method, string $path, array $payload = []): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            throw new \RuntimeException('Apollo API key not configured');
        }

        $url = self::BASE_URI . $path;
        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'no-cache',
                'X-Api-Key'     => $apiKey,
            ],
        ];

        if (!empty($payload)) {
            $options['json'] = $payload;
        }

        $response = $this->httpClient->request($method, $url, $options);
        return $this->handleResponse($response);
    }

    private function handleResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $rawBody = $response->getContent(false);
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $body = $rawBody;
        }

        if ($status >= 400) {
            if ($this->isQuotaExceeded($status, $body)) {
                $this->logger->warning('Apollo API quota exhausted', [
                    'status' => $status,
                    'body' => $body,
                ]);
                throw new ApolloQuotaExceededException('Apollo API quota exhausted');
            }

            // Bubble up rate limit to allow queue retry/backoff
            if ($status === 429) {
                throw new \RuntimeException('Apollo API error 429 rate limit');
            }

            $this->logger->error('Apollo API error', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException('Apollo API error '.$status);
        }

        return is_array($body) ? $body : [];
    }

    private function isQuotaExceeded(int $status, $body): bool
    {
        $haystack = '';
        if (is_array($body)) {
            $haystack = strtolower(json_encode($body) ?: '');
        } elseif (is_string($body)) {
            $haystack = strtolower($body);
        }

        $quotaTerms = [
            'out of credits',
            'insufficient credits',
            'credits exhausted',
            'quota exhausted',
            'credit limit',
            'daily limit reached',
            'usage limit reached',
            'payment required',
        ];

        foreach ($quotaTerms as $needle) {
            if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        if ($status === 402) {
            return true;
        }

        if ($status === 403) {
            foreach (['quota', 'credit', 'limit', 'payment required'] as $needle) {
                if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
