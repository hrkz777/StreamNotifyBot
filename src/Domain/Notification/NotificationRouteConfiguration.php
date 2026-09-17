<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use InvalidArgumentException;

final readonly class NotificationRouteConfiguration
{
    /**
     * @param iterable<NotificationRouteWebhook> $webhooks
     * @param iterable<string> $streamerIds
     */
    public function __construct(
        public NotificationRoute $route,
        iterable $webhooks,
        iterable $streamerIds,
    ) {
        $webhookItems = array_values([...$webhooks]);
        $eventTypes = [];
        foreach ($webhookItems as $webhook) {
            if ($webhook->notificationRouteId !== $route->id || isset($eventTypes[$webhook->eventType->value])) {
                throw new InvalidArgumentException('通知設定Webhookの構成が不正です。');
            }
            $eventTypes[$webhook->eventType->value] = true;
        }
        $this->webhooks = $webhookItems;

        $streamerItems = array_values([...$streamerIds]);
        $streamerIdsById = [];
        foreach ($streamerItems as $streamerId) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $streamerId) !== 1
                || isset($streamerIdsById[$streamerId])) {
                throw new InvalidArgumentException('通知対象の配信者IDが不正です。');
            }
            $streamerIdsById[$streamerId] = true;
        }
        $this->streamerIds = $streamerItems;
    }

    /** @var list<NotificationRouteWebhook> */
    public array $webhooks;

    /** @var list<string> */
    public array $streamerIds;
}
