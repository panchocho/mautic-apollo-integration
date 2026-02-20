<?php

namespace MauticPlugin\MauticApolloBundle\EventListener;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Event\ChannelSubscriptionChange;
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
            LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED => ['onDncChange', 0],
        ];
    }

    public function onDncChange(ChannelSubscriptionChange $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            return;
        }

        if ($event->getChannel() !== 'email') {
            return;
        }

        // Only react when a lead becomes not-contactable
        if ($event->getNewStatus() === DoNotContact::IS_CONTACTABLE) {
            return;
        }

        $lead = $event->getLead();
        $payload = [
            'type' => 'optout',
            'leadId' => $lead ? $lead->getId() : null,
            'reason' => $event->getNewStatusVerb(),
        ];

        $this->queue->enqueue('optout', json_encode($payload));
    }
}
