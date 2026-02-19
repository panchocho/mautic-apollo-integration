<?php

namespace MauticPlugin\ApolloBundle\Service;

use Mautic\CompanyBundle\Entity\Company;
use Mautic\CompanyBundle\Model\CompanyModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class SyncService
{
    private const CONTACT_ID_FIELD = 'apollo_contact_id';
    private const COMPANY_ID_FIELD = 'apollo_company_id';

    private IntegrationHelper $integrationHelper;
    private ApolloApiClient $client;
    private LeadModel $leadModel;
    private CompanyModel $companyModel;
    private LoggerInterface $logger;

    public function __construct(
        IntegrationHelper $integrationHelper,
        ApolloApiClient $client,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        LoggerInterface $logger
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->client            = $client;
        $this->leadModel         = $leadModel;
        $this->companyModel      = $companyModel;
        $this->logger            = $logger;
    }

    /**
     * Pull contacts from Apollo into Mautic.
     * Returns number of processed records.
     */
    public function pullFromApollo(): int
    {
        if (!$this->client->isConfigured()) {
            return 0;
        }

        // Minimal stub: perform search with updated_at filter using stored cursor
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        $settings    = [];
        if ($integration && method_exists($integration, 'getIntegrationSettings')) {
            $integrationSettings = $integration->getIntegrationSettings();
            if ($integrationSettings && method_exists($integrationSettings, 'getFeatureSettings')) {
                $settings = $integrationSettings->getFeatureSettings() ?? [];
            }
        } elseif ($integration && method_exists($integration, 'mergeConfigToFeatureSettings')) {
            $settings = $integration->mergeConfigToFeatureSettings() ?? [];
        }
        $cursor = $settings['last_sync_ts'] ?? null;

        $page      = 1;
        $processed = 0;
        $latestTs  = $cursor;

        do {
            $query = [
                'page'          => $page,
                'person_titles' => [],
            ];
            if ($cursor) {
                $query['updated_at'] = ['gte' => $cursor];
            }

            $response = $this->client->searchContacts($query);
            $contacts = $response['contacts'] ?? [];

            foreach ($contacts as $contact) {
                $this->upsertLeadFromApollo($contact);
                $processed++;
                if (!empty($contact['updated_at'])) {
                    $latestTs = max($latestTs ?? $contact['updated_at'], $contact['updated_at']);
                }
            }

            $hasNext = !empty($response['pagination']['next_page']);
            $page++;
        } while ($hasNext);

        $this->logger->info('Pulled contacts from Apollo', ['count' => $processed, 'last_ts' => $latestTs]);

        // Save new cursor (latest updated_at) into integration settings
        if ($latestTs && $integration && method_exists($integration, 'getIntegrationSettings')) {
            $integrationSettings = $integration->getIntegrationSettings();
            if ($integrationSettings && method_exists($integrationSettings, 'setFeatureSettings')) {
                $integrationSettings->setFeatureSettings(array_merge($settings, ['last_sync_ts' => $latestTs]));
            }
        }

        return $processed;
    }

    private function upsertLeadFromApollo(array $contact): void
    {
        $email = $contact['email'] ?? null;
        if (!$email) {
            return;
        }

        $repo = $this->leadModel->getRepository();

        // Prefer matching by stored Apollo contact id, fallback to email
        $lead = null;
        if (!empty($contact['id']) && $this->repoHasField($repo, self::CONTACT_ID_FIELD)) {
            $lead = $repo->findOneBy([self::CONTACT_ID_FIELD => $contact['id']]);
        }
        if (!$lead) {
            $lead = $repo->findOneBy(['email' => $email]);
        }
        if (!$lead instanceof Lead) {
            $lead = new Lead();
            $lead->setEmail($email);
        }

        if (!empty($contact['first_name'])) {
            $lead->setFirstname($contact['first_name']);
        }
        if (!empty($contact['last_name'])) {
            $lead->setLastname($contact['last_name']);
        }
        if (!empty($contact['title'])) {
            if (method_exists($lead, 'setJobTitle')) {
                $lead->setJobTitle($contact['title']);
            } elseif (method_exists($lead, 'setTitle')) {
                $lead->setTitle($contact['title']);
            } elseif (method_exists($lead, 'addUpdatedField')) {
                $lead->addUpdatedField('position', $contact['title']);
            }
        }
        if (!empty($contact['phone'])) {
            $lead->setPhone($contact['phone']);
        }

        // Persist Apollo contact id for future upserts
        if (!empty($contact['id'])) {
            $this->setFieldValue($lead, self::CONTACT_ID_FIELD, $contact['id']);
        }

        // Company mapping
        if (!empty($contact['organization_name']) || !empty($contact['domain'])) {
            $companyName = $contact['organization_name'] ?? $contact['domain'];
            $domain      = $contact['domain'] ?? null;
            $companyRepo = $this->companyModel->getRepository();
            // first try by stored Apollo company id
            $company = null;
            if (!empty($contact['organization_id']) && $this->companyRepoHasField($companyRepo, self::COMPANY_ID_FIELD)) {
                $company = $companyRepo->findOneBy([self::COMPANY_ID_FIELD => $contact['organization_id']]);
            }
            if (!$company) {
                $company = $companyRepo->findOneBy(['companyname' => $companyName]);
            }
            if (!$company instanceof Company) {
                $company = new Company();
                $company->setCompanyname($companyName);
                if ($domain && method_exists($company, 'setWebsite')) {
                    $company->setWebsite($domain);
                }
                $this->companyModel->saveEntity($company);
            }
            if (!empty($contact['organization_id'])) {
                $this->setCompanyFieldValue($company, self::COMPANY_ID_FIELD, $contact['organization_id']);
                $this->companyModel->saveEntity($company);
            }
            if (method_exists($lead, 'addCompany')) {
                $lead->addCompany($company);
            }
        }

        $this->leadModel->saveEntity($lead);
    }

    private function fieldAlias(string $field): string
    {
        return $field;
    }

    private function setFieldValue(Lead $lead, string $field, $value): void
    {
        if (method_exists($lead, 'setFieldValue')) {
            $lead->setFieldValue($field, $value);
        } elseif (method_exists($lead, 'addUpdatedField')) {
            $lead->addUpdatedField($field, $value);
        }
    }

    private function setCompanyFieldValue(Company $company, string $field, $value): void
    {
        if (method_exists($company, 'setFieldValue')) {
            $company->setFieldValue($field, $value);
        } elseif (method_exists($company, 'addUpdatedField')) {
            $company->addUpdatedField($field, $value);
        }
    }

    private function repoHasField($repo, string $field): bool
    {
        if (method_exists($repo, 'getClassMetadata')) {
            $meta = $repo->getClassMetadata();
            return $meta->hasField($field);
        }
        return false;
    }

    private function companyRepoHasField($repo, string $field): bool
    {
        if (method_exists($repo, 'getClassMetadata')) {
            $meta = $repo->getClassMetadata();
            return $meta->hasField($field);
        }
        return false;
    }
}
