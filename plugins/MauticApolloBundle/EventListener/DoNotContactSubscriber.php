<?php

namespace Mautic\ApolloBundle\EventListener;

use Mautic\ApolloBundle\Service\QueueService;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Event\DoNotContactEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;

class DoNotContactSubscriber extends CommonSubscriber
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
            LeadEvents::LEAD_DONOT_CONTACT_ADD => ['onDncAdd', 0],
        ];
    }

    public function onDncAdd(DoNotContactEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            return;
        }

        $lead = $event->getLead();
        $payload = [
            'type' => 'optout',
            'leadId' => $lead ? $lead->getId() : null,
            'reason' => $event->getReason(),
        ];

        $this->queue->enqueue('optout', json_encode($payload));
    }
}
