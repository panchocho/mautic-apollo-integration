<?php

namespace Apollo\MauticBundle\EventListener;

use Apollo\MauticBundle\Service\QueueService;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\PluginBundle\Helper\IntegrationHelper;

class LeadUpdateSubscriber extends CommonSubscriber
{
    private QueueService $queue;
    private IntegrationHelper $integrationHelper;

    public function __construct(QueueService $queue, IntegrationHelper $integrationHelper)
    {
        $this->queue             = $queue;
        $this->integrationHelper = $integrationHelper;
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

        $lead    = $event->getLead();
        $payload = [
            'type' => 'lead_update',
            'leadId' => $lead->getId(),
            'fields' => $lead->getProfileFields(),
        ];

        $this->queue->enqueue('lead_update', json_encode($payload));
    }
}
