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
                'controller' => 'Mautic\\ApolloBundle\\Controller\\WebhookController::testAction',
                'methods'    => ['GET'],
            ],
            'apollo_queue_push' => [
                'path'       => '/plugin/apollo/push',
                'controller' => 'Mautic\\ApolloBundle\\Controller\\WebhookController::pushAction',
                'methods'    => ['POST'],
            ],
        ],
    ],
    'services'    => [
        'events'     => [
            'apollo.form.subscriber' => [
                'class'     => 'Mautic\\ApolloBundle\\EventListener\\FormSubmissionSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
            'apollo.lead.subscriber' => [
                'class'     => 'Mautic\\ApolloBundle\\EventListener\\LeadUpdateSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
            'apollo.dnc.subscriber' => [
                'class'     => 'Mautic\\ApolloBundle\\EventListener\\DoNotContactSubscriber',
                'arguments' => [
                    'apollo.sync.queue',
                    'mautic.helper.integration',
                ],
            ],
        ],
        'forms'      => [],
        'integrations' => [
            'apollo.integration' => [
                'class'     => 'Mautic\\ApolloBundle\\Integration\\ApolloIntegration',
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
                'class'     => 'Mautic\\ApolloBundle\\Command\\PullCommand',
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
                'class'     => 'Mautic\\ApolloBundle\\Command\\RetryCommand',
                'arguments' => [
                    'apollo.sync.queue',
                    'logger',
                ],
                'tag' => 'console.command',
            ],
        ],
        'other'      => [
            'apollo.api.client' => [
                'class'     => 'Mautic\\ApolloBundle\\Service\\ApolloApiClient',
                'arguments' => [
                    'mautic.helper.integration',
                    'translator',
                    'http_client',
                    'logger',
                ],
            ],
            'apollo.sync.service' => [
                'class'     => 'Mautic\\ApolloBundle\\Service\\SyncService',
                'arguments' => [
                    'mautic.helper.integration',
                    'apollo.api.client',
                    'mautic.lead.model.lead',
                    'mautic.company.model.company',
                    'logger',
                ],
            ],
            'apollo.sync.queue' => [
                'class'     => 'Mautic\\ApolloBundle\\Service\\QueueService',
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
