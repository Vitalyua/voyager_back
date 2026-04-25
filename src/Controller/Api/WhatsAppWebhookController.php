<?php

namespace App\Controller\Api;

use App\Service\WhatsAppService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WhatsAppWebhookController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WhatsAppService $whatsAppService,
    ) {
    }

    #[Route('/api/whatsapp/webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            $this->logger->warning('WhatsApp webhook: invalid JSON payload');
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($payload as $event) {
            $eventType = $event['eventType'] ?? null;

            if ($eventType === 'Microsoft.EventGrid.SubscriptionValidationEvent') {
                return $this->handleSubscriptionValidation($event);
            }

            $this->handleEvent($eventType, $event);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleSubscriptionValidation(array $event): JsonResponse
    {
        $validationCode = $event['data']['validationCode'] ?? null;

        $this->logger->info('WhatsApp webhook: subscription validation', [
            'validationCode' => $validationCode,
        ]);

        return new JsonResponse(['validationResponse' => $validationCode]);
    }

    private function handleEvent(?string $eventType, array $event): void
    {
        $data = $event['data'] ?? [];

        match ($eventType) {
            'Microsoft.Communication.AdvancedMessageReceived' => $this->handleMessageReceived($data),
            'Microsoft.Communication.AdvancedMessageAnalysisCompleted' => $this->logger->info(
                'WhatsApp webhook: message analysis completed',
                [
                    'data' => $data,
                ],
            ),
            default => $this->logger->info('WhatsApp webhook: unhandled event type', [
                'eventType' => $eventType,
            ]),
        };
    }

    private function handleMessageReceived(array $data): void
    {
        $from = $data['from'] ?? null;
        $content = $data['content'] ?? null;

        $this->logger->info('WhatsApp webhook: message received', [
            'from' => $from,
            'to' => $data['to'] ?? null,
            'content' => $content,
        ]);

        if ($from) {
            $this->whatsAppService->markApprovedAndSendPending($from);
            $this->logger->info('WhatsApp: client approved, 24h window opened', ['phone' => $from]);
        }
    }
}
