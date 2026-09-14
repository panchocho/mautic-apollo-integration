<?php

namespace MauticPlugin\MauticApolloBundle\Service;

use MauticPlugin\MauticApolloBundle\Entity\QueueItem;
use MauticPlugin\MauticApolloBundle\Exception\ApolloQuotaExceededException;
use MauticPlugin\MauticApolloBundle\Service\ApolloApiClient;
use MauticPlugin\MauticApolloBundle\Service\SyncContext;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class QueueService
{
    private const CONTACT_ID_FIELD = 'apollo_contact_id';
    private const COMPANY_ID_FIELD = 'apollo_company_id';
    private const FAILED_RETENTION_DAYS = 7;
    private const PROCESS_BATCH_SIZE = 25;
    private const RATE_LIMIT_DELAY_MINUTES = 5;

    private EntityManagerInterface $em;
    private ApolloApiClient $client;
    private LeadModel $leadModel;
    private CompanyModel $companyModel;
    private IntegrationHelper $integrationHelper;
    private SyncContext $syncContext;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $em,
        ApolloApiClient $client,
        LeadModel $leadModel,
        CompanyModel $companyModel,
        IntegrationHelper $integrationHelper,
        SyncContext $syncContext,
        LoggerInterface $logger
    )
    {
        $this->em     = $em;
        $this->client = $client;
        $this->leadModel = $leadModel;
        $this->companyModel = $companyModel;
        $this->integrationHelper = $integrationHelper;
        $this->syncContext = $syncContext;
        $this->logger = $logger;
    }

    public function enqueue(string $type, string $payload): void
    {
        if ($this->syncContext->isApolloImportRunning()) {
            return;
        }

        // Avoid enqueuing the same lead repeatedly during imports or edits.
        if (in_array($type, ['lead_update', 'form_submission', 'optout'], true)) {
            $decoded = json_decode($payload, true);
            $leadId = is_array($decoded) ? (int) ($decoded['leadId'] ?? 0) : 0;
            if ($leadId > 0) {
                $existing = $this->em->getRepository(QueueItem::class)
                    ->createQueryBuilder('q')
                    ->select('q.id')
                    ->where('q.status = :status')
                    ->andWhere('q.type = :type')
                    ->andWhere('q.payload LIKE :leadId')
                    ->setParameter('status', QueueItem::STATUS_PENDING)
                    ->setParameter('type', $type)
                    ->setParameter('leadId', '%"leadId":'.$leadId.'%')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                if ($existing !== null) {
                    return;
                }
            }
        }

        $item = new QueueItem();
        $item->setType($type);
        $item->setPayload($payload);
        $item->setStatus(QueueItem::STATUS_PENDING);
        $item->setNextAttemptAt(new \DateTimeImmutable());

        $this->em->persist($item);
        $this->em->flush();
    }

    public function processPending(): int
    {
        $repo = $this->em->getRepository(QueueItem::class);
        $now = new \DateTimeImmutable();
        $qb = $repo->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.nextAttemptAt <= :now')
            ->setParameter('status', QueueItem::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('q.createdAt', 'ASC')
            ->setMaxResults(self::PROCESS_BATCH_SIZE);
        $items = $qb->getQuery()->getResult();

        $processed = 0;
        foreach ($items as $item) {
            try {
                $payload = json_decode($item->getPayload(), true) ?? [];
                $this->dispatchByType($item->getType(), $payload);
                $this->em->remove($item);
                $processed++;
            } catch (ApolloQuotaExceededException $e) {
                $this->logger->warning('Apollo quota exhausted while processing queue; clearing pending batch', [
                    'id' => $item->getId(),
                    'type' => $item->getType(),
                ]);
                $item->setStatus(QueueItem::STATUS_FAILED);
                $this->em->persist($item);
                $this->markRemainingBatchFailed($items, $item);
                break;
            } catch (\Throwable $e) {
                $this->logger->error('Queue item failed', [
                    'id' => $item->getId(),
                    'type' => $item->getType(),
                    'exception' => $e,
                ]);
                $item->incrementAttempts();
                $this->em->persist($item);
                if ($this->isRateLimit($e)) {
                    $retryAt = $now->modify('+'.self::RATE_LIMIT_DELAY_MINUTES.' minutes');
                    $item->setNextAttemptAt($retryAt);
                    $item->setStatus(QueueItem::STATUS_PENDING);
                    $this->reschedulePendingQueue($retryAt);
                    break;
                } elseif ($this->isQuotaExhausted($e)) {
                    $item->setStatus(QueueItem::STATUS_FAILED);
                } elseif ($item->getAttempts() < 5) {
                    $delayMinutes = min(30, pow(2, $item->getAttempts()));
                    $item->setNextAttemptAt($now->modify('+' . $delayMinutes . ' minutes'));
                    $item->setStatus(QueueItem::STATUS_PENDING);
                } else {
                    $item->setStatus(QueueItem::STATUS_FAILED);
                }
            }
        }

        $this->em->flush();
        return $processed;
    }

    public function purgeStaleFailedItems(?int $olderThanDays = null): int
    {
        $olderThanDays = $olderThanDays ?? self::FAILED_RETENTION_DAYS;
        $cutoff = new \DateTimeImmutable('-'.$olderThanDays.' days');

        $qb = $this->em->createQueryBuilder();
        $query = $qb->delete(QueueItem::class, 'q')
            ->where('q.status = :status')
            ->andWhere('q.createdAt < :cutoff')
            ->setParameter('status', QueueItem::STATUS_FAILED)
            ->setParameter('cutoff', $cutoff)
            ->getQuery();

        return $query->execute();
    }

    private function markRemainingBatchFailed(array $items, QueueItem $currentItem): void
    {
        $mark = false;
        foreach ($items as $item) {
            if ($mark) {
                $item->setStatus(QueueItem::STATUS_FAILED);
                $this->em->persist($item);
                continue;
            }

            if ($item === $currentItem) {
                $mark = true;
            }
        }
    }

    private function reschedulePendingQueue(\DateTimeImmutable $retryAt): void
    {
        $this->em->createQueryBuilder()
            ->update(QueueItem::class, 'q')
            ->set('q.nextAttemptAt', ':retryAt')
            ->where('q.status = :status')
            ->andWhere('q.nextAttemptAt < :retryAt')
            ->setParameter('status', QueueItem::STATUS_PENDING)
            ->setParameter('retryAt', $retryAt)
            ->getQuery()
            ->execute();
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
            'title'      => $fields['position'] ?? null,
            'email'      => $fields['email'] ?? null,
            'phone'      => $fields['phone'] ?? null,
        ];

        // include stored Apollo contact id for upsert if available
        $apolloId = $this->getFieldValue($lead, self::CONTACT_ID_FIELD);
        if ($apolloId) {
            $payload['id'] = $apolloId;
        }

        // Attach the primary company if present. LeadModel returns hydrated arrays in Mautic 7.
        $companies = $this->leadModel->getCompanies($lead);
        $company   = null;
        if (!empty($companies)) {
            $primaryCompanies = array_filter(
                $companies,
                static fn (array $candidate): bool => !empty($candidate['is_primary'])
            );
            $company = reset($primaryCompanies) ?: reset($companies);
            if (is_array($company)) {
                $payload['company_name'] = $company['companyname'] ?? null;
                $payload['domain']       = $company['companywebsite'] ?? null;
            }
        }

        $payload = array_filter($payload, static fn($v) => $v !== null && $v !== '');
        if (empty($payload['email'])) {
            $this->logger->warning('Skipping Apollo push for lead without email', [
                'lead_id' => $lead->getId(),
            ]);
            return;
        }

        $response = $this->client->upsertContact($payload);

        // capture returned Apollo IDs
        $contactData = $response['contact'] ?? $response['person'] ?? $response ?? [];
        if (is_array($contactData)) {
            if (!empty($contactData['id'])) {
                $this->setFieldValue($lead, self::CONTACT_ID_FIELD, $contactData['id']);
            }
            if (!empty($contactData['organization_id']) && is_array($company)) {
                $companyId     = $company['company_id'] ?? $company['id'] ?? null;
                $companyEntity = $companyId ? $this->companyModel->getEntity((int) $companyId) : null;
                if ($companyEntity) {
                    $this->setCompanyFieldValue($companyEntity, self::COMPANY_ID_FIELD, $contactData['organization_id']);
                    $this->companyModel->saveEntity($companyEntity);
                }
            }
            $this->leadModel->saveEntity($lead);
        }
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

    private function isQuotaExhausted(\Throwable $e): bool
    {
        if ($e instanceof ApolloQuotaExceededException) {
            return true;
        }

        $message = strtolower($e->getMessage());
        foreach ([
            'out of credits',
            'insufficient credits',
            'credits exhausted',
            'quota exhausted',
            'credit limit',
            'daily limit reached',
            'usage limit reached',
            'payment required',
        ] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getFieldValue(Lead $lead, string $field)
    {
        if (method_exists($lead, 'getFieldValue')) {
            return $lead->getFieldValue($field);
        }
        if (method_exists($lead, 'getProfileFields')) {
            $fields = $lead->getProfileFields();
            return $fields[$field] ?? null;
        }
        return null;
    }

    private function setFieldValue(Lead $lead, string $field, $value): void
    {
        if (method_exists($lead, 'setFieldValue')) {
            $lead->setFieldValue($field, $value);
        } elseif (method_exists($lead, 'addUpdatedField')) {
            $lead->addUpdatedField($field, $value);
        }
    }

    private function setCompanyFieldValue($company, string $field, $value): void
    {
        if ($company && method_exists($company, 'setFieldValue')) {
            $company->setFieldValue($field, $value);
        } elseif ($company && method_exists($company, 'addUpdatedField')) {
            $company->addUpdatedField($field, $value);
        }
    }
}
