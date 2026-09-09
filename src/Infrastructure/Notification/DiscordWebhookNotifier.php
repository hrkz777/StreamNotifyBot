<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DiscordWebhookNotifier
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /** @param array<string, mixed> $payload */
    public function send(string $webhookUrl, array $payload): void
    {
        $parts = parse_url($webhookUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'discord.com' || !is_string($parts['path'] ?? null) || preg_match('#^/api/webhooks/[0-9]+/[A-Za-z0-9_\-]+$#D', $parts['path']) !== 1 || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) {
            throw new InvalidArgumentException('Discord Webhook URLの形式が不正です。');
        }
        try {
            $response = $this->httpClient->request('POST', $webhookUrl, ['json' => $payload, 'max_redirects' => 0, 'timeout' => 10.0]);
            if (!in_array($response->getStatusCode(), [200, 204], true)) {
                throw new \RuntimeException('Discord通知を送信できませんでした。');
            }
        } catch (TransportExceptionInterface) {
            throw new \RuntimeException('Discord通知を送信できませんでした。');
        }
    }
}
