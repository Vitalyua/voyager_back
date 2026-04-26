<?php

namespace App\Controller\Api;

use App\Entity\AcceptanceCheck;
use App\Entity\Attachment;
use App\Entity\AwbEvent;
use App\Entity\FailureReason;
use App\Entity\Notification;
use App\Entity\NotifiedContact;
use App\Service\AttachmentStorage;
use App\Service\OneRecordClient;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class ScanController extends AbstractController
{
    private const string AWB_REGEX = '[0-9]{3}-[0-9]{8}';
    private const string CARGO_NS = 'https://onerecord.iata.org/ns/cargo#';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OneRecordClient $oneRecord,
        private readonly LoggerInterface $logger,
        private readonly AttachmentStorage $attachments,
        private readonly WhatsAppService $whatsApp,
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

        $foh = $this->em->getRepository(AcceptanceCheck::class)
            ->findOneBy(
                ['waybillPrefix' => $prefix, 'waybillNumber' => $number, 'type' => AcceptanceCheck::TYPE_FOH],
                ['createdAt' => 'DESC'],
            );

        $shipment = null;
        if ($shipmentId = $note->getLogisticObjectId()) {
            try {
                $shipment = $this->oneRecord->getLogisticsObject($shipmentId);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to fetch shipment for scan', [
                    'awb' => $awb,
                    'shipment_id' => $shipmentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $parties = array_map(
            static fn (NotifiedContact $c) => [
                'name'    => $c->getName(),
                'role'    => $c->getRole(),
                'channel' => $c->getChannel(),
                'email'   => $c->getEmail(),
                'phone'   => $c->getPhone(),
            ],
            $note->getContacts()->toArray(),
        );

        return $this->json([
            'awb'             => $awb,
            'notification_id' => $note->getId(),
            'route'           => $this->extractRoute($note->getFlights()),
            'cargo'           => $this->extractCargo($shipment),
            'pcs'             => $this->extractPcs($shipment),
            'kg'              => $this->extractKg($shipment),
            'fohConfirmedAt'  => $foh?->getFohConfirmedAt()?->format('H:i\Z'),
            'parties'         => $parties,
        ]);
    }

    #[Route('/api/scan/{awb}/foh', methods: ['POST'], requirements: ['awb' => self::AWB_REGEX])]
    public function confirmFoh(string $awb): JsonResponse
    {
        [$prefix, $number] = $this->splitAwb($awb);

        $now = new \DateTimeImmutable();

        $check = (new AcceptanceCheck())
            ->setWaybillPrefix($prefix)
            ->setWaybillNumber($number)
            ->setType(AcceptanceCheck::TYPE_FOH)
            ->setFohConfirmedAt($now);

        $this->em->persist($check);

        $notification = $this->em->getRepository(Notification::class)
            ->findOneBy(
                ['waybillPrefix' => $prefix, 'waybillNumber' => $number],
                ['created' => 'DESC'],
            );

        if ($notification) {
            $awbEvent = $this->em->getRepository(AwbEvent::class)
                ->findOneBy(['notification' => $notification, 'code' => 'FOH']);

            $awbEvent?->setActualTime($now);
        }

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

        $payloadRaw = $request->request->get('payload');
        $body = is_string($payloadRaw)
            ? (json_decode($payloadRaw, true) ?? [])
            : (json_decode($request->getContent(), true) ?? []);

        $check = (new AcceptanceCheck())
            ->setWaybillPrefix($prefix)
            ->setWaybillNumber($number)
            ->setType(AcceptanceCheck::TYPE_FAILURE);

        $notification = isset($body['notification_id'])
            ? $this->em->getRepository(Notification::class)->find((int) $body['notification_id'])
            : $this->em->getRepository(Notification::class)->findOneBy(
                ['waybillPrefix' => $prefix, 'waybillNumber' => $number],
                ['created' => 'DESC'],
            );

        $reasonsByIndex = [];
        foreach (array_values($body['reasons'] ?? []) as $i => $r) {
            if (empty($r['code'])) {
                continue;
            }
            $reason = (new FailureReason())
                ->setCode((string)$r['code'])
                ->setComment(isset($r['comment']) ? (string)$r['comment'] : null);
            if ($notification) {
                $notification->addReason($reason);
            } else {
                $this->em->persist($reason);
            }
            $reasonsByIndex[$i] = $reason;
        }

        if (!empty($body['notify']) && is_array($body['contacts'] ?? null) && $notification) {
            foreach ($body['contacts'] as $c) {
                $contact = (new NotifiedContact())
                    ->setName((string)($c['name'] ?? ''))
                    ->setRole((string)($c['role'] ?? ''))
                    ->setChannel((string)($c['channel'] ?? 'Email'))
                    ->setEmail(!empty($c['email']) ? (string)$c['email'] : null)
                    ->setPhone(!empty($c['phone']) ? (string)$c['phone'] : null);
                $notification->addContact($contact);
            }
        }

        $files = $request->files->get('files');
        if (is_array($files)) {
            foreach ($files as $i => $bucket) {
                $reason = $reasonsByIndex[(int)$i] ?? null;
                if (!$reason || !is_array($bucket)) {
                    continue;
                }
                foreach ($bucket as $file) {
                    if (!$file instanceof UploadedFile) {
                        continue;
                    }
                    try {
                        $attachment = $this->attachments->save($file, $awb);
                    } catch (\InvalidArgumentException $e) {
                        return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
                    }
                    $reason->addAttachment($attachment);
                }
            }
        }

        $this->em->persist($check);
        $this->em->flush();

        if (!empty($body['notify']) && $notification) {
            $reasonText = implode(', ', array_map(
                static fn(FailureReason $r) => $r->getCode(),
                array_values($reasonsByIndex),
            ));
            $message = sprintf('AWB %s acceptance failure. Reasons: %s', $awb, $reasonText ?: 'N/A');
            foreach ($notification->getContacts() as $contact) {
                $phone = $contact->getPhone();
                if (!$phone) continue;
                try {
                    $this->whatsApp->sendMessage($phone, $message);
                } catch (\Throwable $e) {
                    $this->logger->warning('WhatsApp send failed', [
                        'phone' => $phone,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $this->json(['ok' => true, 'id' => $check->getId()]);
    }

    #[Route('/api/scan/attachments/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function attachment(int $id): Response
    {
        $attachment = $this->em->getRepository(Attachment::class)->find($id);
        if (!$attachment) {
            return new JsonResponse(['error' => 'Attachment not found'], Response::HTTP_NOT_FOUND);
        }

        $notification = $attachment->getFailureReason()?->getNotification();
        if (!$notification) {
            return new JsonResponse(['error' => 'Attachment is detached'], Response::HTTP_NOT_FOUND);
        }

        $awb = $notification->getWaybillPrefix() . '-' . $notification->getWaybillNumber();
        $path = $this->attachments->path($attachment, $awb);
        if (!is_file($path)) {
            return new JsonResponse(['error' => 'File missing on disk'], Response::HTTP_GONE);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->getMime());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $attachment->getName(),
        );
        return $response;
    }

    private function splitAwb(string $awb): array
    {
        $parts = explode('-', $awb, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }


    private function extractRoute(?array $flights): array
    {
        $legs = $flights['legs'] ?? null;
        if (!is_array($legs)) {
            return [];
        }
        $codes = [];
        foreach ($legs as $i => $leg) {
            if ($i === 0 && !empty($leg['from'])) {
                $codes[] = (string)$leg['from'];
            }
            if (!empty($leg['to'])) {
                $codes[] = (string)$leg['to'];
            }
        }
        return $codes;
    }

    private function extractCargo(?array $shipment): ?string
    {
        if (!is_array($shipment)) {
            return null;
        }
        foreach (['productCategory', 'goodsDescription', 'goodsType'] as $field) {
            $val = $shipment[self::CARGO_NS . $field] ?? null;
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }
        return null;
    }

    private function extractPcs(?array $shipment): ?int
    {
        $pieces = $shipment[self::CARGO_NS . 'pieces'] ?? null;
        return is_array($pieces) ? count($pieces) : null;
    }

    private function extractKg(?array $shipment): ?string
    {
        $value = $shipment[self::CARGO_NS . 'totalGrossWeight'][self::CARGO_NS . 'numericalValue'] ?? null;
        return $value !== null ? (string)$value : null;
    }

    private function extractParties(?array $shipment): array
    {
        $rawParties = $shipment[self::CARGO_NS . 'involvedParties'] ?? null;
        if (!is_array($rawParties)) {
            return [];
        }

        $result = [];
        foreach ($rawParties as $party) {
            $roleRef = $party[self::CARGO_NS . 'partyRole']['@id'] ?? null;
            $detailsRef = $party[self::CARGO_NS . 'partyDetails']['@id'] ?? null;

            $name = null;
            if (is_string($detailsRef) && ($id = $this->extractObjectId($detailsRef)) !== null) {
                try {
                    $details = $this->oneRecord->getLogisticsObject($id);
                    $name = $this->extractPartyName($details);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to fetch party details', [
                        'party_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $result[] = [
                'name' => $name,
                'role' => $this->mapRole(is_string($roleRef) ? $this->extractRoleCode($roleRef) : null),
            ];
        }
        return $result;
    }

    private function extractPartyName(?array $details): ?string
    {
        if (!is_array($details)) {
            return null;
        }
        foreach (['legalName', 'name', 'companyName'] as $field) {
            $val = $details[self::CARGO_NS . $field] ?? null;
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }
        return null;
    }

    private function extractRoleCode(string $iri): ?string
    {
        $base = basename($iri);
        if (str_contains($base, '#')) {
            $base = substr($base, strrpos($base, '#') + 1);
        }
        return $base !== '' ? $base : null;
    }

    private function mapRole(?string $code): ?string
    {
        return match ($code) {
            'SHP' => 'Shipper',
            'CNE' => 'Consignee',
            'FFW' => 'Freight forwarder',
            'AGT' => 'Agent',
            'CAR' => 'Carrier',
            'NFY' => 'Notify party',
            null => null,
            default => $code,
        };
    }

    private function extractObjectId(string $iri): ?string
    {
        if (!preg_match('~/logistics-objects/([a-f0-9-]+)/?$~i', $iri, $m)) {
            return null;
        }
        return $m[1];
    }
}
