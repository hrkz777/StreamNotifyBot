<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookEvent
{
    public string $payload;

    public function __construct(
        public string $id,
        public string $subscriptionId,
        string $payload,
        DateTimeImmutable $receivedAt,
    ) {
        self::assertUuidV7($id, 'WebhookイベントID');
        self::assertUuidV7($subscriptionId, 'Webhook購読ID');
        if ($payload === '' || strlen($payload) > 1048576) {
            throw new InvalidArgumentException('Webhookイベント本文は1バイト以上1MiB以下で指定してください。');
        }

        $this->payload = $payload;
        $this->receivedAt = $receivedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $receivedAt;

    public function payloadHash(): string
    {
        return hash('sha256', $this->payload, true);
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }
}
