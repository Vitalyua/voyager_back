<?php
// src/Service/OneRecordClient.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OneRecordClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ONERECORD_BASE_URL)%')]   private readonly string $baseUrl,
        #[Autowire('%env(ONERECORD_AUTH_URL)%')]   private readonly string $authUrl,
        #[Autowire('%env(ONERECORD_CLIENT_ID)%')]  private readonly string $clientId,
        #[Autowire('%env(ONERECORD_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(ONERECORD_PUBLISHER)%')]  private readonly string $publisher,
    ) {
    }

    public function getLogisticsObject(string $id): array
    {
        return $this->get(sprintf('/api/%s/logistics-objects/%s/', $this->publisher, $id));
    }

    public function getLogisticsEvents(string $id): array
    {
        return $this->get(sprintf('/api/%s/logistics-objects/%s/logistics-events', $this->publisher, $id));
    }

    private function get(string $path): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getToken(),
                'Accept'        => 'application/ld+json',
            ],
        ]);

        return $response->toArray(false);
    }

    private function getToken(): string
    {
        return $this->cache->get('onerecord_token', function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', $this->authUrl, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = $response->toArray();

            // expires_in в секундах, берём с запасом 30 сек чтобы не словить протухший токен
            $item->expiresAfter(max(60, (int) ($data['expires_in'] ?? 300) - 30));

            $this->logger->info('OneRecord token obtained', [
                'expires_in' => $data['expires_in'] ?? null,
            ]);

            return $data['access_token'];
        });
    }
}