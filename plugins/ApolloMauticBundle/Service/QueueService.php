<?php

namespace Apollo\MauticBundle\Service;

use Apollo\MauticBundle\Entity\QueueItem;
use Apollo\MauticBundle\Service\ApolloApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CompanyBundle\Model\CompanyModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class QueueService
{
    private EntityManagerInterface $em;
    private ApolloApiClient $client;
    private LeadModel $leadModel;
    private CompanyModel $companyModel;
    private IntegrationHelper $integrationHelper;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $em,
        ApolloApiClient $client,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        IntegrationHelper $integrationHelper,
        LoggerInterface $logger
    )
    {
        $this->em     = $em;
        $this->client = $client;
        $this->leadModel = $leadModel;
        $this->companyModel = $companyModel;
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;
    }

    public function enqueue(string $type, string $payload): void
    {
        $item = new QueueItem();
        $item->setType($type);
        $item->setPayload($payload);
        $item->setStatus(QueueItem::STATUS_PENDING);

        $this->em->persist($item);
        $this->em->flush();
    }

    public function processPending(): int
    {
        $repo = $this->em->getRepository(QueueItem::class);
        $items = $repo->findBy(['status' => QueueItem::STATUS_PENDING], null, 50);

        $processed = 0;
        foreach ($items as $item) {
            try {
                $payload = json_decode($item->getPayload(), true) ?? [];
                $this->dispatchByType($item->getType(), $payload);
                $item->setStatus(QueueItem::STATUS_DONE);
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error('Queue item failed', [
                    'id' => $item->getId(),
                    'type' => $item->getType(),
                    'exception' => $e,
                ]);
                // simple retry strategy: leave as pending on 429, else mark failed
                if ($this->isRateLimit($e)) {
                    $item->setStatus(QueueItem::STATUS_PENDING);
                } else {
                    $item->setStatus(QueueItem::STATUS_FAILED);
                }
            }
        }

        $this->em->flush();
        return $processed;
    }

    private function dispatchByType(string $type, array $payload): void
    {
        switch ($type) {
            case 'lead_update':
            case 'form_submission':
                if (!empty($payload['leadId'])) {
                    $lead = $this->leadModel->getEntity($payload['leadId']);
                    if ($lead instanceof Lead) {
                        $this->pushLeadToApollo($lead);
                    }
                }
                break;
            case 'optout':
                if (!empty($payload['leadId'])) {
                    $lead = $this->leadModel->getEntity($payload['leadId']);
                    if ($lead instanceof Lead) {
                        $this->pushOptOut($lead, $payload['reason'] ?? 'unsubscribe');
                    }
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown queue type '.$type);
        }
    }

    private function pushLeadToApollo(Lead $lead): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            throw new \RuntimeException('Apollo integration not configured');
        }

        $fields = $lead->getProfileFields();
        $payload = [
            'first_name' => $fields['firstname'] ?? $fields['first_name'] ?? null,
            'last_name'  => $fields['lastname'] ?? $fields['last_name'] ?? null,
            'name'       => trim(($fields['firstname'] ?? '').' '.($fields['lastname'] ?? '')),
            'title'      => $fields['position'] ?? $fields['title'] ?? null,
            'email'      => $fields['email'] ?? null,
            'phone'      => $fields['phone'] ?? null,
        ];

        // Attach company if present
        $companies = method_exists($lead, 'getCompanies') ? $lead->getCompanies() : [];
        if (!empty($companies)) {
            $company = is_array($companies) ? reset($companies) : $companies->first();
            if ($company) {
                $payload['company_name'] = $company->getName();
                $payload['domain']       = $company->getWebsite();
            }
        }

        $payload = array_filter($payload, static fn($v) => $v !== null && $v !== '');
        if (empty($payload['email'])) {
            throw new \RuntimeException('Cannot push lead without email');
        }

        $this->client->upsertContact($payload);
    }

    private function pushOptOut(Lead $lead, string $reason): void
    {
        $email = $lead->getEmail();
        if (!$email) {
            return;
        }
        $this->client->request('PUT', '/contacts/unsubscribe', [
            'emails' => [$email],
            'reason' => $reason,
        ]);
    }

    private function isRateLimit(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return (strpos($message, '429') !== false) || (strpos($message, 'rate') !== false);
    }
}
