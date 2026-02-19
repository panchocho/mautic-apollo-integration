<?php

namespace MauticPlugin\MauticApolloBundle\Controller;

use MauticPlugin\MauticApolloBundle\Service\QueueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController extends AbstractController
{
    private QueueService $queueService;

    public function __construct(QueueService $queueService)
    {
        $this->queueService = $queueService;
    }

    public function testAction(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'source' => 'apollo-plugin']);
    }

    public function pushAction(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $type = $request->get('type', 'manual');
        $this->queueService->enqueue($type, $payload ?: '{}');

        return new JsonResponse(['queued' => true]);
    }
}
