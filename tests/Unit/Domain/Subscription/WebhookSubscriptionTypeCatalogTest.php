<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Subscription\WebhookSubscriptionTypeCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookSubscriptionTypeCatalogTest extends TestCase
{
    #[Test]
    public function itDefinesTheRequiredTypesForEveryPlatform(): void
    {
        self::assertSame(['channel.feed'], WebhookSubscriptionTypeCatalog::forPlatform(Platform::YouTube));
        self::assertSame(
            ['stream.online', 'stream.offline', 'channel.update'],
            WebhookSubscriptionTypeCatalog::forPlatform(Platform::Twitch),
        );
        self::assertSame(
            ['livestart', 'liveend', 'liveschedulecreate', 'livescheduleupdate', 'livescheduledelete'],
            WebhookSubscriptionTypeCatalog::forPlatform(Platform::TwitCasting),
        );
    }
}
