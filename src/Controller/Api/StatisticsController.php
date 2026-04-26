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

    #[Route('/api/statistics/late-awb', methods: ['GET'])]
    public function lateAwb(EntityManagerInterface $em): JsonResponse
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT n.id, n.waybill_prefix, n.waybill_number, n.created
             FROM notification n
             WHERE n.waybill_prefix IS NOT NULL AND n.waybill_number IS NOT NULL
               AND n.id NOT IN (
                 SELECT DISTINCT n2.id
                 FROM notification n2
                 JOIN awb_event e ON e.notification_id = n2.id
                 WHERE
                   (e.actual_time IS NOT NULL AND e.estimated_time < e.actual_time)
                   OR
                   (e.actual_time IS NULL AND e.estimated_time < NOW())
               )
             ORDER BY n.created DESC'
        );

        return $this->json($rows);
    }

    #[Route('/api/statistics/failure-reasons', methods: ['GET'])]
    public function failureReasons(EntityManagerInterface $em): JsonResponse
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT fr.id, fr.code, fr.comment, fr.resolved_at, fr.created_at,
                    n.waybill_prefix, n.waybill_number
             FROM failure_reason fr
             JOIN notification n ON fr.notification_id = n.id
             ORDER BY fr.created_at DESC
             LIMIT 20'
        );

        return $this->json($rows);
    }

    #[Route('/api/statistics/awb-with-uld', methods: ['GET'])]
    public function awbWithUld(EntityManagerInterface $em): JsonResponse
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT id, waybill_prefix, waybill_number, uld, created
             FROM notification
             WHERE uld IS NOT NULL
             ORDER BY created DESC
             LIMIT 20'
        );

        foreach ($rows as &$row) {
            $row['uld'] = json_decode($row['uld'], true);
        }

        return $this->json($rows);
    }
}
