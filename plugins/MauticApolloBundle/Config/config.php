<?php

return [
    'name'        => 'Apollo.io Integration',
    'description' => 'Bidirectional sync between Apollo.io and Mautic contacts/companies.',
    'version'     => '0.1.0',
    'author'      => 'Pancho',
    'routes'      => [
        'main' => [
            'apollo_webhook_test' => [
                'path'       => '/plugin/apollo/webhook/test',
                'controller' => 'MauticPlugin\\MauticApolloBundle\\Controller\\WebhookController::testAction',
                'methods'    => ['GET'],
            ],
            'apollo_queue_push' => [
                'path'       => '/plugin/apollo/push',
                'controller' => 'MauticPlugin\\MauticApolloBundle\\Controller\\WebhookController::pushAction',
                'methods'    => ['POST'],
            ],
        ],
    ],
    'services'    => [
        'events'     => [
            'apollo.form.subscriber' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\EventListener\\FormSubmissionSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
            'apollo.lead.subscriber' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\EventListener\\LeadUpdateSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
            'apollo.dnc.subscriber' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\EventListener\\DoNotContactSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
        ],
        'forms'      => [],
        'integrations' => [
            'apollo.integration' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Integration\\ApolloIntegration',
                'arguments' => [
                    'translator',
                    'mautic.helper.integration',
                    'apollo.sync.service',
                ],
            ],
        ],
        'models'     => [],
        'commands'   => [
            'apollo.command.pull' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Command\\PullCommand',
                'arguments' => [
                    'apollo.api.client',
                    'apollo.sync.service',
                    'mautic.lead.model.lead',
                    'mautic.company.model.company',
                    'logger',
                ],
                'tag' => 'console.command',
            ],
            'apollo.command.retry' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Command\\RetryCommand',
                'arguments' => [
                    'apollo.sync.queue',
                    'logger',
                ],
                'tag' => 'console.command',
            ],
        ],
        'other'      => [
            'apollo.api.client' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Service\\ApolloApiClient',
                'arguments' => [
                    'mautic.helper.integration',
                    'translator',
                    'http_client',
                    'logger',
                ],
            ],
            'apollo.sync.service' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Service\\SyncService',
                'arguments' => [
                    'mautic.helper.integration',
                    'apollo.api.client',
                    'mautic.lead.model.lead',
                    'mautic.company.model.company',
                    'logger',
                ],
            ],
            'apollo.sync.queue' => [
                'class'     => 'MauticPlugin\\MauticApolloBundle\\Service\\QueueService',
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'apollo.api.client',
                    'mautic.lead.model.lead',
                    'mautic.company.model.company',
                    'mautic.helper.integration',
                    'logger',
                ],
            ],
        ],
    ],
];
