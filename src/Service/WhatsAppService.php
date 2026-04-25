<?php

namespace App\Service;

use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WhatsAppService
{
    private const string TOKEN_CACHE_KEY = 'azure_whatsapp_access_token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseUrl,
        private readonly string $channelId,
    ) {
    }

    private const string API_VERSION = '2024-08-30';
    private const string APPROVED_KEY_PREFIX = 'whatsapp_approved_';
    private const string PENDING_KEY_PREFIX = 'whatsapp_pending_';
    private const int WINDOW_TTL = 82800; // 23 hours

    /**
     * Основной метод: отправляет текст если клиент апрувнул, иначе шлёт шаблон.
     * При отправке шаблона — сохраняет текст, чтобы отправить после апрува.
     */
    public function sendMessage(string $phone, string $text): array
    {
        if ($this->isApproved($phone)) {
            return [
                'sent' => 'text',
                'response' => $this->sendTextMessage($phone, $text),
            ];
        }

        $this->logger->info('WhatsApp: client not approved, sending template + saving pending message', ['phone' => $phone]);
        $this->savePendingMessage($phone, $text);

        return [
            'sent' => 'template',
            'response' => $this->notifyCargoUpdate($phone),
        ];
    }

    public function markApprovedAndSendPending(string $phone): void
    {
        $this->markApproved($phone);

        $pending = $this->getPendingMessage($phone);
        if ($pending) {
            $this->logger->info('WhatsApp: sending pending message after approval', ['phone' => $phone]);
            $this->sendTextMessage($phone, $pending);
            $this->deletePendingMessage($phone);
        }
    }

    public function markApproved(string $phone): void
    {
        $key = self::APPROVED_KEY_PREFIX . $this->normalizePhone($phone);
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item): bool {
            $item->expiresAfter(self::WINDOW_TTL);
            return true;
        });
    }

    public function isApproved(string $phone): bool
    {
        $key = self::APPROVED_KEY_PREFIX . $this->normalizePhone($phone);
        return $this->cache->get($key, function (ItemInterface $item): bool {
            $item->expiresAfter(0);
            return false;
        });
    }

    private function savePendingMessage(string $phone, string $text): void
    {
        $key = self::PENDING_KEY_PREFIX . $this->normalizePhone($phone);
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($text): string {
            $item->expiresAfter(86400);
            return $text;
        });
    }

    private function getPendingMessage(string $phone): ?string
    {
        $key = self::PENDING_KEY_PREFIX . $this->normalizePhone($phone);
        $value = $this->cache->get($key, function (ItemInterface $item): bool {
            $item->expiresAfter(0);
            return false;
        });

        return $value !== false ? $value : null;
    }

    private function deletePendingMessage(string $phone): void
    {
        $this->cache->delete(self::PENDING_KEY_PREFIX . $this->normalizePhone($phone));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    public function sendTextMessage(string $to, string $content): array
    {
        return $this->send([
            'channelRegistrationId' => $this->channelId,
            'to' => [$to],
            'kind' => 'text',
            'content' => $content,
        ]);
    }

    public function notifyCargoUpdate(string $phone): array
    {
        return $this->sendTemplate($phone, 'voyager_updates', 'en');
    }

    public function sendTemplate(string $to, string $templateName, string $language, array $values = [], array $bindings = []): array
    {
        $template = [
            'name' => $templateName,
            'language' => $language,
        ];
        if ($values) {
            $template['values'] = $values;
        }
        if ($bindings) {
            $template['bindings'] = $bindings;
        }

        return $this->send([
            'channelRegistrationId' => $this->channelId,
            'to' => [$to],
            'kind' => 'template',
            'template' => $template,
        ]);
    }

    private function send(array $data, bool $isRetry = false): array
    {
        $token = $this->getAccessToken();
        $url = rtrim($this->baseUrl, '/') . '/messages/notifications:send';

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'query' => [
                'api-version' => self::API_VERSION,
            ],
            'json' => $data,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 && !$isRetry) {
            $this->logger->info('WhatsApp API returned 401, refreshing token and retrying');
            $this->cache->delete(self::TOKEN_CACHE_KEY);
            return $this->send($data, true);
        }

        $content = $response->toArray(false);

        if ($statusCode >= 400) {
            $this->logger->error('WhatsApp API error', [
                'status' => $statusCode,
                'response' => $content,
            ]);
        }

        return $content;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function getAccessToken(): string
    {
        return $this->cache->get(self::TOKEN_CACHE_KEY, function (ItemInterface $item): string {
            $tokenUrl = sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
                $this->tenantId,
            );

            $response = $this->httpClient->request('POST', $tokenUrl, [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://communication.azure.com/.default',
                ],
            ]);

            $data = $response->toArray();

            $expiresIn = ($data['expires_in'] ?? 3600) - 60;
            $item->expiresAfter($expiresIn > 0 ? $expiresIn : 3540);

            $this->logger->info('WhatsApp: obtained new Azure AD access token');

            return $data['access_token'];
        });
    }
}
