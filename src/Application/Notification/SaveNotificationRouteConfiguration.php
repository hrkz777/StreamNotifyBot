<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Domain\Notification\NotificationEventType;
use App\Domain\Notification\NotificationRoute;
use App\Domain\Notification\NotificationRouteConfiguration;
use App\Domain\Notification\NotificationRouteRepository;
use App\Domain\Notification\NotificationRouteWebhook;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use InvalidArgumentException;

final readonly class SaveNotificationRouteConfiguration
{
    public function __construct(
        private NotificationRouteRepository $repository,
        private SecretCipher $secretCipher,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): string
    {
        $routeId = $this->routeId($values);
        $route = new NotificationRoute(
            $routeId,
            $this->requiredString($values, 'name', '通知設定名'),
            $this->optionalString($values, 'description'),
            $this->requiredString($values, 'color', '表示色'),
            $this->boolean($values, 'is_enabled'),
        );
        $webhooks = [];
        foreach ($this->webhookUrls($values) as [$eventType, $url]) {
            $webhookId = $this->idGenerator->generate();
            try {
                $webhooks[] = new NotificationRouteWebhook(
                    $webhookId,
                    $routeId,
                    $eventType,
                    $this->secretCipher->encrypt($url, SecretPurpose::DiscordWebhookUrl, $webhookId),
                    $this->clock->now(),
                    $this->clock->now(),
                );
            } finally {
                sodium_memzero($url);
            }
        }
        $this->repository->saveConfiguration(new NotificationRouteConfiguration($route, $webhooks, $this->streamerIds($values)));

        return $routeId;
    }

    /** @param array<string, mixed> $values */
    private function routeId(array $values): string
    {
        $id = $values['id'] ?? null;
        if ($id === null || $id === '') {
            return $this->idGenerator->generate();
        }
        if (!is_string($id)) {
            throw new InvalidArgumentException('通知設定IDの形式が不正です。');
        }

        return $id;
    }

    /** @param array<string, mixed> $values
     * @return list<array{NotificationEventType, string}>
     */
    private function webhookUrls(array $values): array
    {
        $input = $values['webhook_urls'] ?? [];
        if (!is_array($input)) {
            throw new InvalidArgumentException('Webhook URLの形式が不正です。');
        }
        $urls = [];
        foreach ($input as $event => $url) {
            if (!is_string($event) || !is_string($url) || trim($url) === '') {
                continue;
            }
            $eventType = NotificationEventType::tryFrom($event);
            if ($eventType === null || !$this->isDiscordWebhookUrl($url) || isset($urls[$eventType->value])) {
                throw new InvalidArgumentException('Discord Webhook URLの形式が不正です。');
            }
            $urls[$eventType->value] = [$eventType, trim($url)];
        }

        return array_values($urls);
    }

    private function isDiscordWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null) || !is_string($parts['path'] ?? null)) {
            return false;
        }

        return in_array(strtolower($parts['host']), ['discord.com', 'discordapp.com'], true)
            && preg_match('#^/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$#D', $parts['path']) === 1;
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private function streamerIds(array $values): array
    {
        $ids = $values['streamer_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || !array_all($ids, 'is_string')) {
            throw new InvalidArgumentException('通知対象の配信者指定が不正です。');
        }

        $result = [];
        foreach ($ids as $id) {
            $result[] = $id;
        }

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key, string $label): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException($label.'を入力してください。');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('説明の形式が不正です。');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function boolean(array $values, string $key): bool
    {
        return in_array($values[$key] ?? null, [true, 1, '1', 'true'], true);
    }
}
