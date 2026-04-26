<?php

namespace App\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StatisticsController extends AbstractController
{
    #[Route('/api/statistics', methods: ['GET'])]
    public function index(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();

        $totalAwb = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT CONCAT(waybill_prefix, \'-\', waybill_number))
             FROM notification
             WHERE waybill_prefix IS NOT NULL AND waybill_number IS NOT NULL'
        );

        $onTimeAwb = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT n.id)
             FROM notification n
             JOIN awb_event e ON e.notification_id = n.id
             WHERE
               (e.actual_time IS NOT NULL AND e.estimated_time > e.actual_time)
               OR
               (e.actual_time IS NULL AND e.estimated_time > NOW())'
        );

        $openFailureReasons = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM failure_reason WHERE resolved_at IS NULL'
        );

        $lastNotificationAt = $conn->fetchOne(
            'SELECT MAX(created) FROM notification'
        );

        return $this->json([
            'total_awb'             => $totalAwb,
            'on_time_awb'           => $onTimeAwb,
            'open_failure_reasons'  => $openFailureReasons,
            'last_notification_at'  => $lastNotificationAt,
        ]);
    }
}
