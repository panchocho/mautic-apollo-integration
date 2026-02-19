<?php

namespace Mautic\ApolloBundle\EventListener;

use Mautic\ApolloBundle\Service\QueueService;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\FormBundle\FormEvents as MauticFormEvents;
use Mautic\FormBundle\Event\FormSubmitEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;

class FormSubmissionSubscriber extends CommonSubscriber
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
            MauticFormEvents::FORM_POST_SAVE => ['onFormSubmit', 0],
        ];
    }

    public function onFormSubmit(FormSubmitEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject('Apollo');
        if (!$integration || !$integration->isConfigured()) {
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
