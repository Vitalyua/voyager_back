<?php

namespace App\Controller\Api;

use App\Entity\Notification;
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

        $log = (new Notification())
            ->setJson(is_array($body) ? $body : ['raw' => $body])
            ->setLogisticObjectType($objectType)
            ->setLogisticObjectId($objectId);

        // если это Shipment — подтягиваем waybill, prefix/number и сразу генерим flights
        if ($objectType === 'Shipment' && $objectId !== null) {
            try {
                $shipment   = $client->getLogisticsObject($objectId);
                $waybillRef = $shipment['https://onerecord.iata.org/ns/cargo#waybill']['@id'] ?? null;

                if (is_string($waybillRef) && ($waybillId = $this->extractObjectId($waybillRef)) !== null) {
                    $waybill = $client->getLogisticsObject($waybillId);

                    $prefix = $waybill['https://onerecord.iata.org/ns/cargo#waybillPrefix'] ?? null;
                    $number = $waybill['https://onerecord.iata.org/ns/cargo#waybillNumber'] ?? null;

                    $log->setWaybillPrefix(is_string($prefix) ? $prefix : null);
                    $log->setWaybillNumber(is_string($number) ? $number : null);

                    if (!empty($prefix) && !empty($number)) {
                        // сразу прибиваем рандомный рейс — сохраняется в БД, не нужно дёргать каждый раз в листинге
                        $log->setFlights($this->pickRandomFlight());
                        // roadmap пока пустой — будем заполнять отдельно
                        $log->setRoadmap(null);

                        $em->persist($log);
                        $em->flush();
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
            'id'             => $log->getId(),
            'waybill_prefix' => $log->getWaybillPrefix(),
            'waybill_number' => $log->getWaybillNumber(),
        ]);
    }
    private function buildRoadmap(array $leg): array
    {
        $std = new \DateTimeImmutable($leg['departure']);
        $ata = new \DateTimeImmutable($leg['arrival']);

        // [code, name, base, offset_min_minutes, offset_max_minutes]
        // отрицательные значения = до базы, положительные = после
        $milestones = [
            ['RCS', 'Ready for Carriage',          $std, -120, -90],
            ['MAN', 'Manifested',                  $std,  -75, -60],
            ['FOC', 'Freight on Board',            $std,  -45, -30],
            ['DEP', 'Departed',                    $std,  -15,  15],
            ['SAC', 'Shipment Accepted at Transit',$ata,   60,  90],
        ];

        $roadmap = [];
        foreach ($milestones as [$code, $name, $base, $minOffset, $maxOffset]) {
            $roadmap[] = [
                'code' => $code,
                'name' => $name,
                'min'  => $this->shiftMinutes($base, $minOffset)->format('c'),
                'max'  => $this->shiftMinutes($base, $maxOffset)->format('c'),
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
                'pieces'               => $enriched['pieces'],
                'last_event'           => $enriched['last_event'],
                'departureLocation'    => $enriched['departureLocation'],
                'arrivalLocation'      => $enriched['arrivalLocation'],
                'flight'               => $enriched['flight'],
                'roadmap'              => $enriched['roadmap'],
            ];
        }

        return $this->json([
            'count' => count($result),
            'items' => $result,
        ]);
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
            $leg['roadmap'] = $this->buildRoadmap($leg);
        }
        unset($leg);

        return ['legs' => $fl];
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
}