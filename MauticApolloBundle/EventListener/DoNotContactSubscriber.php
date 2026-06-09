<?php

namespace MauticPlugin\MauticApolloBundle\EventListener;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use MauticPlugin\MauticApolloBundle\Service\SyncContext;
use Mautic\LeadBundle\Event\DoNotContactAddEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DoNotContactSubscriber implements EventSubscriberInterface
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
            DoNotContactAddEvent::ADD_DONOT_CONTACT => ['onDncAdd', 0],
        ];
    }

    public function onDncAdd(DoNotContactAddEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            return;
        }
        if ($this->syncContext->isApolloImportRunning()) {
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
