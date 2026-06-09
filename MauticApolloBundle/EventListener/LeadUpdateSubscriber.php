<?php

namespace MauticPlugin\MauticApolloBundle\EventListener;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use MauticPlugin\MauticApolloBundle\Service\SyncContext;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LeadUpdateSubscriber implements EventSubscriberInterface
{
    private QueueService $queue;
    private IntegrationHelper $integrationHelper;
    private SyncContext $syncContext;

    public function __construct(QueueService $queue, IntegrationHelper $integrationHelper, SyncContext $syncContext)
    {
        $this->queue             = $queue;
        $this->integrationHelper = $integrationHelper;
        $this->syncContext       = $syncContext;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_SAVE => ['onLeadSave', 0],
        ];
    }

    public function onLeadSave(LeadEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            return;
        }
        if ($this->syncContext->isApolloImportRunning()) {
            return;
        }

        $lead    = $event->getLead();
        $payload = [
            'type' => 'lead_update',
            'leadId' => $lead->getId(),
            'fields' => $lead->getProfileFields(),
        ];

        $this->queue->enqueue('lead_update', json_encode($payload));
    }
}
