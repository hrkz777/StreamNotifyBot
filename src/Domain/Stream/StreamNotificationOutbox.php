<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class StreamNotificationOutbox
{
    public function __construct(public string $id, public string $platformVideoId, public StreamNotificationType $type, public string $payloadHash, public DateTimeImmutable $occurredAt)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $platformVideoId) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $payloadHash) !== 1) {
            throw new InvalidArgumentException('通知アウトボックスの値が不正です。');
        }
    }
}
