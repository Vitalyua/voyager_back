<?php

namespace App\Controller\Api;

use App\Entity\AcceptanceCheck;
use App\Entity\Attachment;
use App\Entity\FailureReason;
use App\Entity\Notification;
use App\Entity\NotifiedContact;
use App\Entity\AwbEvent;
use App\Service\OneRecordClient;
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
    private function pickRandomCommodity(): string
    {
        $commodities = ['PIL', 'PER', 'DGR', 'GEN'];
        return $commodities[array_rand($commodities)];
    }

    #[Route('/api/prox', name: 'prox', methods: ['POST'])]
    public function prox(Request $request, EntityManagerInterface $em, OneRecordClient $client): JsonResponse
    {
        $payload = $request->getContent();
        $data    = json_decode($payload, true) ?? [];
        $body    = $data ?: $payload;

        $objectType = null;
        $objectId   = null;

        if (is_array($body)) {
            $rawType = $body['https://onerecord.iata.org/ns/api#hasLogisticsObjectType'] ?? null;
            if (is_string($rawType)) {
                $objectType = str_contains($rawType, '#')
                    ? substr($rawType, strrpos($rawType, '#') + 1)
                    : $rawType;
            }
            $rawObject = $body['https://onerecord.iata.org/ns/api#hasLogisticsObject']['@id'] ?? null;
            if (is_string($rawObject)) {
                $objectId = $this->extractObjectId($rawObject) ?? basename($rawObject);
            }
        }

        $log = null;

        if ($objectType === 'Shipment' && $objectId !== null) {
            try {
                $shipment   = $client->getLogisticsObject($objectId);
                $waybillRef = $shipment['https://onerecord.iata.org/ns/cargo#waybill']['@id'] ?? null;

                if (is_string($waybillRef) && ($waybillId = $this->extractObjectId($waybillRef)) !== null) {
                    $waybill = $client->getLogisticsObject($waybillId);

                    $prefix = $waybill['https://onerecord.iata.org/ns/cargo#waybillPrefix'] ?? null;
                    $number = $waybill['https://onerecord.iata.org/ns/cargo#waybillNumber'] ?? null;

                    if (!empty($prefix) && !empty($number)) {
                        // ищем существующую нотификацию по prefix+number
                        $existing = $em->getRepository(Notification::class)->findOneBy([
                            'waybillPrefix' => $prefix,
                            'waybillNumber' => $number,
                        ]);

                        if ($existing !== null) {
                            // обновляем: только базовые поля, без flights/commodity/awbEvents
                            $existing
                                ->setJson(is_array($body) ? $body : ['raw' => $body])
                                ->setLogisticObjectType($objectType)
                                ->setLogisticObjectId($objectId);

                            $em->flush();
                            $log = $existing;
                        } else {
                            // создаём новую со всей обвязкой
                            $log = (new Notification())
                                ->setJson(is_array($body) ? $body : ['raw' => $body])
                                ->setLogisticObjectType($objectType)
                                ->setLogisticObjectId($objectId)
                                ->setWaybillPrefix($prefix)
                                ->setWaybillNumber($number);

                            $flights = $this->pickRandomFlight();
                            $log->setFlights($flights);
                            $log->setRoadmap(null);
                            $log->setCommodity($this->pickRandomCommodity());

                            $firstLeg = $flights['legs'][0] ?? null;
                            if ($firstLeg !== null) {
                                foreach ($this->buildAwbEvents($log, $firstLeg, 0) as $event) {
                                    $log->addAwbEvent($event);
                                }
                            }

                            $em->persist($log);
                            $em->flush();
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to enrich shipment notification with waybill', [
                    'shipment_id' => $objectId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $this->json([
            'status'         => 'ok',
            'id'             => $log?->getId(),
            'waybill_prefix' => $log?->getWaybillPrefix(),
            'waybill_number' => $log?->getWaybillNumber(),
        ]);
    }
    private function buildRoadmap(array $leg): array
    {
        $std = new \DateTimeImmutable($leg['departure']);

        // [code, name, offset_minutes от STD]
        $milestones = [
            ['FOH', 'Freight on Hand',            -360], // -6h
            ['SAC', 'Shipment Accepted',          -300], // -5h
            ['RCS', 'Ready for Carriage',         -240], // -4h
            ['MAN', 'Manifested',                 -120], // -2h
            ['DEP', 'Departed',                      0], // STD
        ];

        $roadmap = [];
        foreach ($milestones as [$code, $name, $offset]) {
            $roadmap[] = [
                'code'           => $code,
                'name'           => $name,
                'estimated_time' => $this->shiftMinutes($std, $offset)->format('c'),
                'actual_time' =>  null,
            ];
        }
        return $roadmap;
    }

    private function shiftMinutes(\DateTimeImmutable $dt, int $minutes): \DateTimeImmutable
    {
        $sign = $minutes >= 0 ? '+' : '-';
        return $dt->modify($sign . abs($minutes) . ' minutes');
    }

    #[Route('/api/notifications/shipments', name: 'notifications_shipments', methods: ['GET'])]
    public function shipmentNotifications(
        Request $request,
        EntityManagerInterface $em,
        OneRecordClient $client,
    ): JsonResponse {
        $limit  = min((int) $request->query->get('limit', 50), 500);
        $offset = max((int) $request->query->get('offset', 0), 0);

        $sql = <<<SQL
        SELECT n.*
        FROM notification n
        INNER JOIN (
            SELECT waybill_prefix, waybill_number, MAX(id) AS max_id
            FROM notification
            WHERE logistic_object_type = :type
              AND waybill_prefix IS NOT NULL
              AND waybill_number IS NOT NULL
            GROUP BY waybill_prefix, waybill_number
        ) latest ON latest.max_id = n.id
        ORDER BY n.created DESC
        LIMIT :limit OFFSET :offset
    SQL;

        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata(Notification::class, 'n');

        $items = $em->createNativeQuery($sql, $rsm)
            ->setParameter('type', 'Shipment')
            ->setParameter('limit', $limit)
            ->setParameter('offset', $offset)
            ->getResult();

        $result = [];
        foreach ($items as $n) {
            /** @var Notification $n */
            $enriched = $this->enrichShipmentNotification($n, $client);

            $result[] = [
                'id'                   => $n->getId(),
                'totalGrossWeight'     => $enriched['totalGrossWeight'],
                'created'              => $n->getCreated()?->format(\DateTimeInterface::ATOM),
                'logistic_object_id'   => $n->getLogisticObjectId(),
                'logistic_object_type' => $n->getLogisticObjectType(),
                'waybill_prefix'       => $n->getWaybillPrefix(),
                'waybill_number'       => $n->getWaybillNumber(),
                'commodity' => $n->getCommodity(),
                'pieces'               => $enriched['pieces'],
                'last_event'           => $enriched['last_event'],
                'departureLocation'    => $enriched['departureLocation'],
                'arrivalLocation'      => $enriched['arrivalLocation'],
                'flight'               => $enriched['flight'],
                'awb_events' => array_map(static fn (\App\Entity\AwbEvent $e) => [
                    'leg'            => $e->getLegIndex(),
                    'code'           => $e->getCode(),
                    'name'           => $e->getName(),
                    'estimated_time' => $e->getEstimatedTime()?->format(\DateTimeInterface::ATOM),
                    'actual_time'    => $e->getActualTime()?->format(\DateTimeInterface::ATOM),
                ], $n->getAwbEvents()->toArray()),
            ];
        }

        return $this->json([
            'count' => count($result),
            'items' => $result,
        ]);
    }
    #[Route('/api/notifications/shipments/detail', name: 'notification_shipment_detail', methods: ['GET'])]
    public function shipmentNotificationDetail(
        Request $request,
        EntityManagerInterface $em,
        OneRecordClient $client,
    ): JsonResponse {
        $notificationId = $request->query->get('notification_id');
        $awbPrefix      = $request->query->get('awb_prefix');
        $awbNumber      = $request->query->get('awb_number');

        if ($notificationId !== null) {
            $notification = $em->getRepository(Notification::class)->find((int) $notificationId);
            if (!$notification) {
                return $this->json(['error' => 'Notification not found'], 404);
            }
        } elseif ($awbPrefix !== null && $awbNumber !== null) {
            $notification = $em->getRepository(Notification::class)->findOneBy(
                ['waybillPrefix' => $awbPrefix, 'waybillNumber' => $awbNumber],
                ['created' => 'DESC'],
            );
            if (!$notification) {
                return $this->json(['error' => 'AWB not found'], 404);
            }
        } else {
            return $this->json(['error' => 'Provide notification_id or both awb_prefix and awb_number'], 400);
        }

        $prefix = $notification->getWaybillPrefix();
        $number = $notification->getWaybillNumber();

        $checks = $em->getRepository(AcceptanceCheck::class)->findBy(
            ['waybillPrefix' => $prefix, 'waybillNumber' => $number],
            ['createdAt' => 'DESC'],
        );

        $enriched = $this->enrichShipmentNotification($notification, $client);

        $notifiedContactsRaw = $em->getRepository(NotifiedContact::class)->findBy(
            ['notificationId' => (string)$notification->getId()],
        );

        $notifiedContacts = array_map(static fn (NotifiedContact $c) => [
            'id'              => $c->getId(),
            'name'            => $c->getName(),
            'role'            => $c->getRole(),
            'channel'         => $c->getChannel(),
            'email'           => $c->getEmail(),
            'phone'           => $c->getPhone(),
            'notification_id' => $c->getNotificationId(),
        ], $notifiedContactsRaw);

        $checksData = array_map(static function (AcceptanceCheck $check) {
            return [
                'id'               => $check->getId(),
                'type'             => $check->getType(),
                'foh_confirmed_at' => $check->getFohConfirmedAt()?->format(\DateTimeInterface::ATOM),
                'created_at'       => $check->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'contacts'         => array_map(static fn (NotifiedContact $c) => [
                    'id'              => $c->getId(),
                    'name'            => $c->getName(),
                    'role'            => $c->getRole(),
                    'channel'         => $c->getChannel(),
                    'email'           => $c->getEmail(),
                    'phone'           => $c->getPhone(),
                    'notification_id' => $c->getNotificationId(),
                ], $check->getContacts()->toArray()),
                'reasons'          => array_map(static fn (FailureReason $r) => [
                    'id'          => $r->getId(),
                    'code'        => $r->getCode(),
                    'comment'     => $r->getComment(),
                    'attachments' => array_map(static fn (Attachment $a) => [
                        'id'         => $a->getId(),
                        'name'       => $a->getName(),
                        'mime'       => $a->getMime(),
                        'created_at' => $a->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                    ], $r->getAttachments()->toArray()),
                ], $check->getReasons()->toArray()),
            ];
        }, $checks);

        return $this->json([
            'id'                   => $notification->getId(),
            'totalGrossWeight'     => $enriched['totalGrossWeight'],
            'created'              => $notification->getCreated()?->format(\DateTimeInterface::ATOM),
            'logistic_object_id'   => $notification->getLogisticObjectId(),
            'logistic_object_type' => $notification->getLogisticObjectType(),
            'waybill_prefix'       => $notification->getWaybillPrefix(),
            'waybill_number'       => $notification->getWaybillNumber(),
            'commodity'            => $notification->getCommodity(),
            'pieces'               => $enriched['pieces'],
            'last_event'           => $enriched['last_event'],
            'departureLocation'    => $enriched['departureLocation'],
            'arrivalLocation'      => $enriched['arrivalLocation'],
            'flight'               => $enriched['flight'],
            'awb_events'           => array_map(static fn (AwbEvent $e) => [
                'leg'            => $e->getLegIndex(),
                'code'           => $e->getCode(),
                'name'           => $e->getName(),
                'estimated_time' => $e->getEstimatedTime()?->format(\DateTimeInterface::ATOM),
                'actual_time'    => $e->getActualTime()?->format(\DateTimeInterface::ATOM),
            ], $notification->getAwbEvents()->toArray()),
            'notified_contacts'    => $notifiedContacts,
            'acceptance_checks'    => $checksData,
        ]);
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('expected string or null');
        }
        return new \DateTimeImmutable($value);
    }

    private function serializeAwbEvent(AwbEvent $e): array
    {
        return [
            'id'              => $e->getId(),
            'notification_id' => $e->getNotification()?->getId(),
            'leg_index'       => $e->getLegIndex(),
            'code'            => $e->getCode(),
            'name'            => $e->getName(),
            'estimated_time'  => $e->getEstimatedTime()?->format(\DateTimeInterface::ATOM),
            'actual_time'     => $e->getActualTime()?->format(\DateTimeInterface::ATOM),
            'created'         => $e->getCreated()?->format(\DateTimeInterface::ATOM),
        ];
    }
    /**
     * Подтягивает по shipment_id из нотификации pieces / last_event / departure / arrival.
     * Любая ошибка по конкретной нотификации не валит весь список.
     */
    private function enrichShipmentNotification(Notification $n, OneRecordClient $client): array
    {
        $empty = [
            'pieces'            => null,
            'totalGrossWeight'  => null,
            'last_event'        => null,
            'departureLocation' => null,
            'arrivalLocation'   => null,
            'flight'            => $n->getFlights(),   // из БД
            'roadmap'           => $n->getRoadmap(),   // из БД, пока null
        ];

        $shipmentId = $n->getLogisticObjectId();
        if (!$shipmentId) {
            return $empty;
        }

        $prefixes = [
            'https://onerecord.iata.org/ns/api#',
            'https://onerecord.iata.org/ns/cargo#',
        ];

        try {
            $details = $client->getLogisticsObject($shipmentId);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch shipment for notification', [
                'notification_id' => $n->getId(),
                'shipment_id'     => $shipmentId,
                'error'           => $e->getMessage(),
            ]);
            return $empty;
        }

        $piecesCount = is_array($details['https://onerecord.iata.org/ns/cargo#pieces'] ?? null)
            ? count($details['https://onerecord.iata.org/ns/cargo#pieces'])
            : null;

        $lastEvent = null;
        try {
            $events    = $client->getLogisticsEvents($shipmentId);
            $eventsTmp = $this->compactJsonLd($events, $prefixes);
            $lastEvent = $eventsTmp['hasItem'][0] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch events for notification', [
                'shipment_id' => $shipmentId,
                'error'       => $e->getMessage(),
            ]);
        }

        $departure = null;
        $arrival   = null;
        $waybillRef = $details['https://onerecord.iata.org/ns/cargo#waybill']['@id'] ?? null;
        if (is_string($waybillRef) && ($waybillId = $this->extractObjectId($waybillRef)) !== null) {
            try {
                $waybill = $client->getLogisticsObject($waybillId);

                $depRaw = $this->resolveLocation(
                    $waybill['https://onerecord.iata.org/ns/cargo#departureLocation']['@id'] ?? null,
                    $client,
                    'departureLocation',
                );
                $arrRaw = $this->resolveLocation(
                    $waybill['https://onerecord.iata.org/ns/cargo#arrivalLocation']['@id'] ?? null,
                    $client,
                    'arrivalLocation',
                );

                $departure = $this->compactJsonLd($depRaw, $prefixes)['locationCodes'] ?? null;
                $arrival   = $this->compactJsonLd($arrRaw, $prefixes)['locationCodes'] ?? null;
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to fetch waybill for notification', [
                    'shipment_id' => $shipmentId,
                    'waybill_id'  => $waybillId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return [
            'pieces'            => $piecesCount,
            'totalGrossWeight'  => $details['https://onerecord.iata.org/ns/cargo#totalGrossWeight']['https://onerecord.iata.org/ns/cargo#numericalValue'] ?? null,
            'last_event'        => $lastEvent,
            'departureLocation' => $departure,
            'arrivalLocation'   => $arrival,
            'flight'            => $n->getFlights(),   // <— из БД, не генерим заново
            'roadmap'           => $n->getRoadmap(),
        ];
    }
    private function resolveLocation(?string $ref, OneRecordClient $client, string $label): ?array
    {
        if (!is_string($ref)) {
            return null;
        }

        $locId = $this->extractObjectId($ref);
        if ($locId === null) {
            return null;
        }

        try {
            return $client->getLogisticsObject($locId);
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to fetch $label", ['id' => $locId, 'error' => $e->getMessage()]);
            return null;
        }
    }
    private function pickRandomFlight(): array
    {
        $flights = [
            [
                ['from' => 'LUX', 'flight' => 'EK46',  'to' => 'DXB', 'departure' => '07:20', 'arrival' => '12:20', 'aircraft' => '380'],
                ['from' => 'DXB', 'flight' => 'EK384', 'to' => 'HKG', 'departure' => '18:00', 'arrival' => '01:25', 'aircraft' => '380'],
            ],
            [
                ['from' => 'LUX', 'flight' => 'AY291', 'to' => 'HEL', 'departure' => '14:20', 'arrival' => '17:00', 'aircraft' => '359'],
                ['from' => 'HEL', 'flight' => 'AY18',  'to' => 'HKG', 'departure' => '20:00', 'arrival' => '08:00', 'aircraft' => '359'],
            ],
        ];

        $baseDate = (new \DateTimeImmutable('today'))
            ->modify('+' . random_int(0, 7) . ' days');

        $fl = $flights[array_rand($flights)];

        foreach ($fl as $i => &$leg) {
            $depDate = $baseDate;
            $arrDate = $i === 1 ? $baseDate->modify('+1 day') : $baseDate;

            [$dh, $dm] = explode(':', $leg['departure']);
            [$ah, $am] = explode(':', $leg['arrival']);

            $leg['departure'] = $depDate->setTime((int) $dh, (int) $dm)->format('c');
            $leg['arrival']   = $arrDate->setTime((int) $ah, (int) $am)->format('c');

            // roadmap живёт внутри лега
//            $leg['roadmap'] = $this->buildRoadmap($leg);
        }
        unset($leg);

        return ['legs' => $fl];
    }

    private function buildAwbEvents(Notification $n, array $leg, int $legIndex): array
    {
        $std = new \DateTimeImmutable($leg['departure']);

        // [code, name, offset_minutes от STD]
        $milestones = [
            ['FOH', 'Freight on Hand',     -360],
            ['SAC', 'Shipment Accepted',   -300],
            ['RCS', 'Ready for Carriage',  -240],
            ['MAN', 'Manifested',          -120],
            ['DEP', 'Departed',               0],
        ];

        $events = [];
        foreach ($milestones as [$code, $name, $offset]) {
            $events[] = (new AwbEvent())
                ->setNotification($n)
                ->setLegIndex($legIndex)
                ->setCode($code)
                ->setName($name)
                ->setEstimatedTime($this->shiftMinutes($std, $offset));
        }

        return $events;
    }

    #[Route('/api/shipments/{id}', name: 'shipment_get', methods: ['GET'])]
    public function shipmentInfo(string $id, OneRecordClient $client): JsonResponse
    {
        try {
            $details = $client->getLogisticsObject($id);
            $events  = $client->getLogisticsEvents($id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch shipment', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->json(['error' => $e->getMessage()], 502);
        }
        // 1) Подтягиваем waybill, если есть ссылка
        $waybill = null;
        $waybillRef = $details['https://onerecord.iata.org/ns/cargo#waybill']['@id'] ?? null;
        if (is_string($waybillRef) && ($waybillId = $this->extractObjectId($waybillRef)) !== null) {
            try {
                $waybill = $client->getLogisticsObject($waybillId);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to fetch waybill', ['id' => $waybillId, 'error' => $e->getMessage()]);
            }
        }
        // 2) подтягиваем departureLocation и arrivalLocation из waybill
        $departureLocation = $this->resolveLocation(
            $waybill['https://onerecord.iata.org/ns/cargo#departureLocation']['@id'] ?? null,
            $client,
            'departureLocation',
        );
        $arrivalLocation = $this->resolveLocation(
            $waybill['https://onerecord.iata.org/ns/cargo#arrivalLocation']['@id'] ?? null,
            $client,
            'arrivalLocation',
        );
//        dd($details);
        // 3) Подтягиваем детали по involvedParties (partyDetails — там Organization/Company)
        $parties = [];
        foreach ($details['https://onerecord.iata.org/ns/cargo#involvedParties'] ?? [] as $party) {
            $partyDetailsRef = $party['https://onerecord.iata.org/ns/cargo#partyDetails']['@id'] ?? null;
            $roleRef         = $party['https://onerecord.iata.org/ns/cargo#partyRole']['@id'] ?? null;

            $partyDetails = null;
            if (is_string($partyDetailsRef) && ($partyId = $this->extractObjectId($partyDetailsRef)) !== null) {
                try {
                    $partyDetails = $client->getLogisticsObject($partyId);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to fetch party', ['id' => $partyId, 'error' => $e->getMessage()]);
                }
            }

            $parties[] = [
                'role'    => is_string($roleRef) ? basename($roleRef) : null, // SHP / CNE / FFW
                'ref'     => $partyDetailsRef,
                'details' => $partyDetails,
            ];
        }
        $prefixes = [
            'https://onerecord.iata.org/ns/api#',
            'https://onerecord.iata.org/ns/cargo#',
        ];
        $details_tmp =$this->compactJsonLd($details, $prefixes);
        $events_tmp =$this->compactJsonLd($events, $prefixes);
        $waybill_tmp =$this->compactJsonLd($waybill, $prefixes);
        $parties_tmp =$this->compactJsonLd($parties, $prefixes);
        $ffw = null;
        foreach ($parties_tmp as $party) {
            if($party['role'] === 'ParticipantIdentifier#FFW') {
                $ffw = $party;
                break;
            }
        }
        $dl_tmp = $this->compactJsonLd($departureLocation, $prefixes);
        $al_tmp = $this->compactJsonLd($arrivalLocation, $prefixes);
        $dliata = $dl_tmp['locationCodes'];
        $aliata = $al_tmp['locationCodes'];
        foreach ($details_tmp['pieces'] as $piece) {
            if (isset($piece['@id'])) {
                $fp_id = $this->extractObjectId($piece['@id']);
                $pieceDetails = $client->getLogisticsObject($fp_id);
//                dump($pieceDetails);
            }
        }
//        die;
//        dd($fp_id);
        return $this->json([
            'id'      => $id,
            'pieces'      => count($details_tmp['pieces']),
//            'totalGrossWeight'      => $details_tmp['totalGrossWeight'],
            'last_event'      =>$events_tmp['hasItem'][0],
//            'details' => $details_tmp,
//            'events'  => $events_tmp,
            'waybillPrefix' => $waybill_tmp['waybillPrefix'],
            'waybillNumber' => $waybill_tmp['waybillNumber'],
            'waybilllastModified' => $waybill_tmp['lastModified'],
            'ffw' => $ffw,
//            'waybill' => $waybill_tmp,
//            'parties' => $parties_tmp,
            'departureLocation' => $dliata,
            'arrivalLocation'   => $aliata,
//            'parties' => $parties_tmp,
//            'departureLocation' => $this->compactJsonLd($departureLocation, $prefixes),
//            'arrivalLocation'   => $this->compactJsonLd($arrivalLocation, $prefixes),
        ]);
    }
    private function extractObjectId(string $iri): ?string
    {
        if (!preg_match('~/logistics-objects/([a-f0-9-]+)/?$~i', $iri, $m)) {
            return null;
        }
        return $m[1];
    }
    /**
     * Рекурсивно срезает указанные префиксы из ключей и строковых значений (@id, @type и т.п.).
     */
    private function compactJsonLd(mixed $value, array $prefixes): mixed
    {
        if (is_string($value)) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    return substr($value, strlen($prefix));
                }
            }
            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $val) {
            if (is_string($key)) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        $key = substr($key, strlen($prefix));
                        break;
                    }
                }
            }
            $result[$key] = $this->compactJsonLd($val, $prefixes);
        }

        return $result;
    }

    #[Route('/api/notifications/{notificationId}/awb-events', name: 'awb_event_create', methods: ['POST'])]
    public function createAwbEvent(
        int $notificationId,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $notification = $em->getRepository(Notification::class)->find($notificationId);
        if (!$notification) {
            return $this->json(['error' => 'Notification not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $code = $data['code'] ?? null;
        $name = $data['name'] ?? null;

        if (!is_string($code) || $code === '' || !is_string($name) || $name === '') {
            return $this->json(['error' => 'Fields "code" and "name" are required'], 400);
        }

        try {
            $estimated = $this->parseDateTime($data['estimated_time'] ?? null);
            $actual    = $this->parseDateTime($data['actual_time']    ?? null);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid datetime: ' . $e->getMessage()], 400);
        }

        $event = (new AwbEvent())
            ->setNotification($notification)
            ->setLegIndex((int) ($data['leg_index'] ?? $data['leg'] ?? 0))
            ->setCode($code)
            ->setName($name)
            ->setEstimatedTime($estimated)
            ->setActualTime($actual);

        $em->persist($event);
        $em->flush();

        return $this->json($this->serializeAwbEvent($event), 201);
    }
    #[Route('/api/awb-events/{id}', name: 'awb_event_update', methods: ['PATCH', 'PUT'])]
    public function updateAwbEvent(
        int $id,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $event = $em->getRepository(AwbEvent::class)->find($id);
        if (!$event) {
            return $this->json(['error' => 'AwbEvent not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('code', $data) && is_string($data['code']) && $data['code'] !== '') {
            $event->setCode($data['code']);
        }
        if (array_key_exists('name', $data) && is_string($data['name']) && $data['name'] !== '') {
            $event->setName($data['name']);
        }
        if (array_key_exists('leg_index', $data)) {
            $event->setLegIndex((int) $data['leg_index']);
        }

        try {
            if (array_key_exists('estimated_time', $data)) {
                $event->setEstimatedTime($this->parseDateTime($data['estimated_time']));
            }
            if (array_key_exists('actual_time', $data)) {
                $event->setActualTime($this->parseDateTime($data['actual_time']));
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid datetime: ' . $e->getMessage()], 400);
        }

        $em->flush();

        return $this->json($this->serializeAwbEvent($event));
    }
}
