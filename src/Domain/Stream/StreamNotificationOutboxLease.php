<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class StreamNotificationOutboxLease
{
    public DateTimeImmutable $until;

    public function __construct(
        public StreamNotificationOutbox $notification,
        public string $token,
        DateTimeImmutable $until,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('通知アウトボックスの処理リーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        $this->until = $until->setTimezone(new DateTimeZone('UTC'));
    }
}
