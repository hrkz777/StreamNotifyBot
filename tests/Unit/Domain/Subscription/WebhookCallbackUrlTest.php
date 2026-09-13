<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Subscription;

use App\Domain\Subscription\WebhookCallbackUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookCallbackUrlTest extends TestCase
{
    #[Test]
    public function itAcceptsAnHttpsUrlWithoutCredentialsOrFragments(): void
    {
        self::assertSame('https://notify.example/callback', (new WebhookCallbackUrl('https://notify.example/callback'))->value);
    }

    #[Test]
    public function itRejectsAUrlThatCannotSafelyReceiveWebhooks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookCallbackUrl('http://user:password@notify.example/callback?token=value');
    }
}
