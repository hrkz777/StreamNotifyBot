<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SentStreamNotification
{
    public DateTimeImmutable $sentAt;

    public function __construct(public string $platformVideoId, public StreamNotificationType $type, DateTimeImmutable $sentAt)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $platformVideoId) !== 1) {
            throw new InvalidArgumentException('送信済み通知のプラットフォーム動画IDは小文字標準形式のUUIDv7で指定してください。');
        }

        $this->sentAt = $sentAt->setTimezone(new DateTimeZone('UTC'));
    }
}
