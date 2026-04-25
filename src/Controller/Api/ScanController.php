<?php

namespace App\Controller\Api;

use App\Entity\AcceptanceCheck;
use App\Entity\FailureReason;
use App\Entity\Notification;
use App\Entity\NotifiedContact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScanController extends AbstractController
{
    private const string AWB_REGEX = '[0-9]{3}-[0-9]{8}';

    public function __construct(
        private readonly EntityManagerInterface $em,
    )
    {
    }

    #[Route('/api/scan/{awb}', methods: ['GET'], requirements: ['awb' => self::AWB_REGEX])]
    public function getAwb(string $awb): JsonResponse
    {
        [$prefix, $number] = $this->splitAwb($awb);

        $note = $this->em->getRepository(Notification::class)
            ->findOneBy(
                ['waybillPrefix' => $prefix, 'waybillNumber' => $number],
                ['created' => 'DESC'],
            );

        if (!$note) {
            return new JsonResponse(['error' => 'AWB not found'], Response::HTTP_NOT_FOUND);
        }

        $json = $note->getJson();

        $foh = $this->em->getRepository(AcceptanceCheck::class)
            ->findOneBy(
                ['waybillPrefix' => $prefix, 'waybillNumber' => $number, 'type' => AcceptanceCheck::TYPE_FOH],
                ['createdAt' => 'DESC'],
            );

        return $this->json([
            'awb' => $awb,
            'route' => $this->extractRoute($json),
            'cargo' => $this->extractCargo($json),
            'pcs' => $this->extractPcs($json),
            'kg' => $this->extractKg($json),
            'fohConfirmedAt' => $foh?->getFohConfirmedAt()?->format('H:i\Z'),
            'parties' => $this->extractParties($json),
        ]);
    }

    #[Route('/api/scan/{awb}/foh', methods: ['POST'], requirements: ['awb' => self::AWB_REGEX])]
    public function confirmFoh(string $awb): JsonResponse
    {
        [$prefix, $number] = $this->splitAwb($awb);

        $check = (new AcceptanceCheck())
            ->setWaybillPrefix($prefix)
            ->setWaybillNumber($number)
            ->setType(AcceptanceCheck::TYPE_FOH)
            ->setFohConfirmedAt(new \DateTimeImmutable());

        $this->em->persist($check);
        $this->em->flush();

        return $this->json(['ok' => true, 'id' => $check->getId()]);
    }

    #[Route('/api/scan/{awb}/accept', methods: ['POST'], requirements: ['awb' => self::AWB_REGEX])]
    public function accept(string $awb, Request $request): JsonResponse
    {
        [$prefix, $number] = $this->splitAwb($awb);
        $body = json_decode($request->getContent(), true) ?? [];

        $check = (new AcceptanceCheck())
            ->setWaybillPrefix($prefix)
            ->setWaybillNumber($number)
            ->setType(AcceptanceCheck::TYPE_ACCEPT);

        if (!empty($body['fohConfirmedAt']) && is_string($body['fohConfirmedAt'])) {
            try {
                $check->setFohConfirmedAt(new \DateTimeImmutable($body['fohConfirmedAt']));
            } catch (\Exception) {
                // ignore unparseable timestamp — leave fohConfirmedAt null
            }
        }

        $this->em->persist($check);
        $this->em->flush();

        return $this->json(['ok' => true, 'id' => $check->getId()]);
    }

    #[Route('/api/scan/{awb}/failure', methods: ['POST'], requirements: ['awb' => self::AWB_REGEX])]
    public function failure(string $awb, Request $request): JsonResponse
    {
        [$prefix, $number] = $this->splitAwb($awb);
        $body = json_decode($request->getContent(), true) ?? [];

        $check = (new AcceptanceCheck())
            ->setWaybillPrefix($prefix)
            ->setWaybillNumber($number)
            ->setType(AcceptanceCheck::TYPE_FAILURE);

        foreach ($body['reasons'] ?? [] as $r) {
            if (empty($r['code'])) {
                continue;
            }
            $reason = (new FailureReason())
                ->setCode((string)$r['code'])
                ->setComment(isset($r['comment']) ? (string)$r['comment'] : null);
            $check->addReason($reason);
        }

        if (!empty($body['notify']) && is_array($body['contacts'] ?? null)) {
            foreach ($body['contacts'] as $c) {
                $contact = (new NotifiedContact())
                    ->setName((string)($c['name'] ?? ''))
                    ->setRole((string)($c['role'] ?? ''))
                    ->setChannel((string)($c['channel'] ?? 'Email'));
                $check->addContact($contact);
            }
        }

        $this->em->persist($check);
        $this->em->flush();

        return $this->json(['ok' => true, 'id' => $check->getId()]);
    }

    /** @return array{0: string, 1: string} */
    private function splitAwb(string $awb): array
    {
        $parts = explode('-', $awb, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    // === notification.json adapters (One Record) =========================
    // TODO: wire to real json structure when example payload is available.
    // Until then return safe stubs so the frontend can render.

    private function extractRoute(array $json): array
    {
        return $json['route'] ?? ['HKG', 'FRA', 'JFK'];
    }

    private function extractCargo(array $json): string
    {
        return $json['cargo'] ?? 'pharma';
    }

    private function extractPcs(array $json): int
    {
        return (int)($json['pcs'] ?? 12);
    }

    private function extractKg(array $json): string
    {
        return (string)($json['kg'] ?? '320.00');
    }

    private function extractParties(array $json): array
    {
        return $json['parties'] ?? [
            ['name' => 'Acme Logistics', 'role' => 'Freight forwarder'],
            ['name' => 'John Doe', 'role' => 'Driver · HKG GH'],
            ['name' => 'Lufthansa Cargo', 'role' => 'Airline'],
            ['name' => 'FreshGoods Pharma', 'role' => 'Shipper'],
        ];
    }
}
