<?php

namespace MauticPlugin\MauticApolloBundle\EventListener;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use MauticPlugin\MauticApolloBundle\Service\SyncContext;
use Mautic\FormBundle\FormEvents as MauticFormEvents;
use Mautic\FormBundle\Event\FormSubmitEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormSubmissionSubscriber implements EventSubscriberInterface
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
            MauticFormEvents::FORM_POST_SAVE => ['onFormSubmit', 0],
        ];
    }

    public function onFormSubmit(FormSubmitEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
            return;
        }
        if ($this->syncContext->isApolloImportRunning()) {
            return;
        }

        $submission = $event->getSubmission();
        $data       = $submission->getResults();
        $lead       = $submission->getLead();

        $payload = [
            'type'   => 'form_submission',
            'leadId' => $lead ? $lead->getId() : null,
            'data'   => $data,
        ];

        $this->queue->enqueue('form_submission', json_encode($payload));
    }
}
