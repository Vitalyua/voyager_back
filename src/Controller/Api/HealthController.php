<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/health', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/api/prox', name: 'prox', methods: ['POST'])]
    public function prox(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true) ?? [];

        $body = $data ?: $payload;

        // вытаскиваем тип и id логистического объекта из notification
        $objectType = null;
        $objectId = null;

        if (is_array($body)) {
            // тип: "https://onerecord.iata.org/ns/cargo#Waybill" → "Waybill"
            $rawType = $body['https://onerecord.iata.org/ns/api#hasLogisticsObjectType'] ?? null;
            if (is_string($rawType)) {
                $objectType = str_contains($rawType, '#')
                    ? substr($rawType, strrpos($rawType, '#') + 1)
                    : $rawType;
            }

            // id: ".../logistics-objects/d8687b07-..." → "d8687b07-..."
            $rawObject = $body['https://onerecord.iata.org/ns/api#hasLogisticsObject']['@id'] ?? null;
            if (is_string($rawObject)) {
                $objectId = basename($rawObject);
            }
        }

        $log = (new Notification())
            ->setJson(is_array($body) ? $body : ['raw' => $body])
            ->setLogisticObjectType($objectType)
            ->setLogisticObjectId($objectId);

        $em->persist($log);
        $em->flush();

        return $this->json(['status' => 'ok', 'id' => $log->getId()]);
    }
}