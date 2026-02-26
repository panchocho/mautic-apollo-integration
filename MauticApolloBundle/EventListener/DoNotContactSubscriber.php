<?php

namespace MauticPlugin\MauticApolloBundle\EventListener;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use Mautic\LeadBundle\Event\DoNotContactAddEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DoNotContactSubscriber implements EventSubscriberInterface
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
            DoNotContactAddEvent::ADD_DONOT_CONTACT => ['onDncAdd', 0],
        ];
    }

    public function onDncAdd(DoNotContactAddEvent $event): void
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
