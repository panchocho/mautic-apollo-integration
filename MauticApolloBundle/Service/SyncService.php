<?php

namespace MauticPlugin\MauticApolloBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Field\SchemaDefinition;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticApolloBundle\Entity\SyncState;
use Psr\Log\LoggerInterface;

class SyncService
{
    private const CONTACT_ID_FIELD = 'apollo_contact_id';
    private const COMPANY_ID_FIELD = 'apollo_company_id';
    private const MAX_CONTACTS_PER_RUN = 1000;

    private IntegrationHelper $integrationHelper;
    private ApolloApiClient $client;
    private EntityManagerInterface $em;
    private LeadModel $leadModel;
    private CompanyModel $companyModel;
    private LoggerInterface $logger;

    public function __construct(
        IntegrationHelper $integrationHelper,
        ApolloApiClient $client,
        EntityManagerInterface $em,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        LoggerInterface $logger
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->client            = $client;
        $this->em                = $em;
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

        $state  = $this->getOrCreateSyncState();
        $cursor = $state->getLastContactsSyncAt();

        $page      = 1;
        $processed = 0;
        $latestTs  = $cursor;

        do {
            $query = [
                'page' => $page,
                'per_page' => 100,
                'sort_by_field' => 'contact_updated_at',
                'sort_ascending' => false,
            ];

            $response = $this->client->searchContacts($query);
            $contacts = $response['contacts'] ?? [];

            foreach ($contacts as $contact) {
                if ($processed >= self::MAX_CONTACTS_PER_RUN) {
                    $this->logger->warning('Apollo pull limit reached for this run', [
                        'limit' => self::MAX_CONTACTS_PER_RUN,
                        'last_ts' => $latestTs ? $latestTs->format('c') : null,
                    ]);
                    break 2;
                }
                $this->upsertLeadFromApollo($contact);
                $processed++;
                if (!empty($contact['updated_at'])) {
                    $ts = new \DateTimeImmutable($contact['updated_at']);
                    $latestTs = $latestTs ? max($latestTs, $ts) : $ts;
                }
            }

            $hasNext = count($contacts) === 100 && $page < 500;
            $page++;
        } while ($hasNext);

        $this->logger->info('Pulled contacts from Apollo', [
            'count' => $processed,
            'limit' => self::MAX_CONTACTS_PER_RUN,
            'last_ts' => $latestTs ? $latestTs->format('c') : null,
        ]);


        return $processed;
    }

    private function truncateString(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value == '') {
            return null;
        }
        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }
        return $value;
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
        if (!empty($contact['id']) && $this->leadHasField(self::CONTACT_ID_FIELD)) {
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
        $phone = $this->truncateString($contact['phone'] ?? null, SchemaDefinition::MAX_VARCHAR_LENGTH);
        if ($phone) {
            $lead->setPhone($phone);
        }

        // Persist Apollo contact id for future upserts
        if (!empty($contact['id']) && $this->leadHasField(self::CONTACT_ID_FIELD)) {
            $this->setFieldValue($lead, self::CONTACT_ID_FIELD, $contact['id']);
        }

        // Company mapping
        if (!empty($contact['organization_name']) || !empty($contact['domain'])) {
            $companyName = $contact['organization_name'] ?? $contact['domain'];
            $domain      = $contact['domain'] ?? null;
            $companyRepo = $this->companyModel->getRepository();
            // first try by stored Apollo company id
            $company = null;
            if (!empty($contact['organization_id']) && $this->companyHasField(self::COMPANY_ID_FIELD)) {
                $company = $companyRepo->findOneBy([self::COMPANY_ID_FIELD => $contact['organization_id']]);
            }
            if (!$company) {
                $company = $companyRepo->findOneBy(['name' => $companyName]);
            }
            if (!$company instanceof Company) {
                $company = new Company();
                $company->setName($companyName);
                if ($domain && method_exists($company, 'setWebsite')) {
                    $company->setWebsite($domain);
                }
                $this->companyModel->saveEntity($company);
            }
            if (!empty($contact['organization_id']) && $this->companyHasField(self::COMPANY_ID_FIELD)) {
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

    private function leadHasField(string $field): bool
    {
        return $this->em->getClassMetadata(Lead::class)->hasField($field);
    }

    private function companyHasField(string $field): bool
    {
        return $this->em->getClassMetadata(Company::class)->hasField($field);
    }
    private function getOrCreateSyncState(): SyncState
    {
        $repo  = $this->em->getRepository(SyncState::class);
        $state = $repo->findOneBy([]) ?? new SyncState();

        return $state;
    }

}
