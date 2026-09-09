<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookEventLease
{
    public DateTimeImmutable $until;

    public function __construct(
        public WebhookEvent $event,
        public string $token,
        DateTimeImmutable $until,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Webhookイベントの処理リーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        $this->until = $until->setTimezone(new DateTimeZone('UTC'));
    }
}
