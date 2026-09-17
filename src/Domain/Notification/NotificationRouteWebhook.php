<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Security\EncryptedSecret;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class NotificationRouteWebhook
{
    public function __construct(
        public string $id,
        public string $notificationRouteId,
        public NotificationEventType $eventType,
        public EncryptedSecret $encryptedUrl,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $notificationRouteId) !== 1) {
            throw new InvalidArgumentException('通知設定Webhookの識別子は小文字標準形式のUUIDv7で指定してください。');
        }
    }
}
