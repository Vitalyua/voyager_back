<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\NotifiedContact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notified-contacts', name: 'api_notified_contact_')]
final class NotifiedContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $contact = (new NotifiedContact())
            ->setName($data['name'] ?? '')
            ->setRole($data['role'] ?? '')
            ->setChannel($data['channel'] ?? 'Email')
            ->setEmail($data['email'] ?? null)
            ->setPhone($data['phone'] ?? null)
            ->setNotificationId($data['notification_id'] ?? null);

        $this->em->persist($contact);
        $this->em->flush();

        return $this->json($this->serialize($contact), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $contact = $this->em->find(NotifiedContact::class, $id);
        if (null === $contact) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('name', $data))            { $contact->setName((string) $data['name']); }
        if (\array_key_exists('role', $data))            { $contact->setRole((string) $data['role']); }
        if (\array_key_exists('channel', $data))         { $contact->setChannel((string) $data['channel']); }
        if (\array_key_exists('email', $data))           { $contact->setEmail($data['email']); }
        if (\array_key_exists('phone', $data))           { $contact->setPhone($data['phone']); }
        if (\array_key_exists('notification_id', $data)) { $contact->setNotificationId($data['notification_id']); }

        $this->em->flush();

        return $this->json($this->serialize($contact));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $contact = $this->em->find(NotifiedContact::class, $id);
        if (null === $contact) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($contact);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function serialize(NotifiedContact $c): array
    {
        return [
            'id'              => $c->getId(),
            'name'            => $c->getName(),
            'role'            => $c->getRole(),
            'channel'         => $c->getChannel(),
            'email'           => $c->getEmail(),
            'phone'           => $c->getPhone(),
            'notification_id' => $c->getNotificationId(),
            'notified_at'     => $c->getNotifiedAt(),
        ];
    }
}
