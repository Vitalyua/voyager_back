<?php

namespace App\Controller\Api;

use App\Entity\FailureReason;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FailureReasonController extends AbstractController
{
    #[Route('/api/failure-reasons/{id}/resolved-at', name: 'failure_reason_update_resolved_at', methods: ['PATCH'])]
    public function updateResolvedAt(
        int $id,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $reason = $em->getRepository(FailureReason::class)->find($id);
        if (!$reason) {
            return $this->json(['error' => 'FailureReason not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (!array_key_exists('resolved_at', $data)) {
            return $this->json(['error' => 'Field "resolved_at" is required'], 400);
        }

        $value = $data['resolved_at'];

        if ($value === null) {
            $reason->setResolvedAt(null);
        } else {
            try {
                $reason->setResolvedAt(new \DateTimeImmutable($value));
            } catch (\Throwable) {
                return $this->json(['error' => 'Invalid datetime format for "resolved_at"'], 400);
            }
        }

        $em->flush();

        return $this->json([
            'id'          => $reason->getId(),
            'resolved_at' => $reason->getResolvedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}