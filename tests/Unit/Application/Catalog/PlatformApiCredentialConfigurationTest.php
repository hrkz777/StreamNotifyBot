<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Domain\Catalog\Platform;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformApiCredentialConfigurationTest extends TestCase
{
    #[Test]
    public function itSerializesAndLoadsThePlatformSpecificConfiguration(): void
    {
        $configuration = PlatformApiCredentialConfiguration::youTube('api-key', str_repeat('a', 32));

        self::assertSame('{"api_key":"api-key","websub_secret":"'.str_repeat('a', 32).'"}', $configuration->toJson());
        $loaded = PlatformApiCredentialConfiguration::fromJson(Platform::YouTube, $configuration->toJson());
        self::assertSame('api-key', $loaded->value('api_key'));
        self::assertSame(str_repeat('a', 32), $loaded->value('websub_secret'));
    }

    #[Test]
    public function itRejectsInvalidPlatformSpecificConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlatformApiCredentialConfiguration::fromJson(Platform::Twitch, '{"client_id":"client"}');
    }
}
