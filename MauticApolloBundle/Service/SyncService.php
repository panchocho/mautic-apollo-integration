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
    private const COUNTRY_BY_EMAIL_SUFFIX = [
        '.pe' => 'Peru',
    ];

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
            $lead = $this->findLeadByApolloId((string) $contact['id']);
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
            $lead->setPosition($contact['title']);
        }
        $phone = $this->truncateString($contact['phone'] ?? null, SchemaDefinition::MAX_VARCHAR_LENGTH);
        if ($phone) {
            $lead->setPhone($phone);
        }

        $country = $this->resolveCountry($contact, $email);
        if ($country && !$lead->getCountry()) {
            $lead->setCountry($country);
        }

        // Persist Apollo contact id for future upserts
        if (!empty($contact['id']) && $this->leadHasField(self::CONTACT_ID_FIELD)) {
            $this->setFieldValue($lead, self::CONTACT_ID_FIELD, $contact['id']);
        }

        // A persisted Lead is required before creating the company relationship.
        $this->leadModel->saveEntity($lead);

        // Company mapping
        if (!empty($contact['organization_name']) || !empty($contact['domain'])) {
            $companyName = $contact['organization_name'] ?? $contact['domain'];
            $domain      = $contact['domain'] ?? null;
            $companyRepo = $this->companyModel->getRepository();
            // first try by stored Apollo company id
            $company = null;
            if (!empty($contact['organization_id']) && $this->companyHasField(self::COMPANY_ID_FIELD)) {
                $company = $this->findCompanyByApolloId((string) $contact['organization_id']);
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
            $this->companyModel->addLeadToCompany($company, $lead);
        }
    }

    private function resolveCountry(array $contact, string $email): ?string
    {
        $apolloCountry = isset($contact['country']) && is_scalar($contact['country'])
            ? (string) $contact['country']
            : null;
        $country = $this->truncateString($apolloCountry, SchemaDefinition::MAX_VARCHAR_LENGTH);
        if ($country) {
            return $country;
        }

        $atPosition = strrpos($email, '@');
        if (false === $atPosition) {
            return null;
        }

        $domain = strtolower(substr($email, $atPosition + 1));
        foreach (self::COUNTRY_BY_EMAIL_SUFFIX as $suffix => $inferredCountry) {
            if (str_ends_with($domain, $suffix)) {
                return $inferredCountry;
            }
        }

        return null;
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

    private function findLeadByApolloId(string $apolloId): ?Lead
    {
        $leadId = $this->findEntityIdByCustomField('leads', self::CONTACT_ID_FIELD, $apolloId);
        $lead   = $leadId ? $this->leadModel->getEntity($leadId) : null;

        return $lead instanceof Lead ? $lead : null;
    }

    private function findCompanyByApolloId(string $apolloId): ?Company
    {
        $companyId = $this->findEntityIdByCustomField('companies', self::COMPANY_ID_FIELD, $apolloId);
        $company   = $companyId ? $this->companyModel->getEntity($companyId) : null;

        return $company instanceof Company ? $company : null;
    }

    private function findEntityIdByCustomField(string $table, string $field, string $value): ?int
    {
        $id = $this->em->getConnection()->createQueryBuilder()
            ->select('entity.id')
            ->from(MAUTIC_TABLE_PREFIX.$table, 'entity')
            ->where('entity.'.$field.' = :fieldValue')
            ->setParameter('fieldValue', $value)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false !== $id ? (int) $id : null;
    }

    private function leadHasField(string $field): bool
    {
        return $this->customFieldExists('lead', $field);
    }

    private function companyHasField(string $field): bool
    {
        return $this->customFieldExists('company', $field);
    }

    private function customFieldExists(string $object, string $field): bool
    {
        $exists = $this->em->getConnection()->createQueryBuilder()
            ->select('1')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'field')
            ->where('field.object = :object')
            ->andWhere('field.alias = :alias')
            ->setParameter('object', $object)
            ->setParameter('alias', $field)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false !== $exists;
    }

    private function getOrCreateSyncState(): SyncState
    {
        $repo  = $this->em->getRepository(SyncState::class);
        $state = $repo->findOneBy([]) ?? new SyncState();

        return $state;
    }

}
