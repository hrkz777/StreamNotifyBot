<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Notification;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\StreamNotificationType;
use App\Infrastructure\Notification\DiscordStreamNotificationPayloadFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscordStreamNotificationPayloadFactoryTest extends TestCase
{
    #[Test]
    public function itCreatesAnEmbedWithTheStreamStartTimeAndThumbnail(): void
    {
        $video = new PlatformVideo('01990d4a-0000-7000-8000-000000000901', '01990d4a-0000-7000-8000-000000000902', 'abcdefghijk', '配信タイトル', new DateTimeImmutable('2026-09-09T00:00:00Z'), null, new DateTimeImmutable('2026-09-09T01:00:00Z'), null, 'https://i.ytimg.com/example.jpg', 'live', new DateTimeImmutable('2026-09-09T01:00:00Z'));

        $payload = (new DiscordStreamNotificationPayloadFactory())->create(StreamNotificationType::Started, $video, new DateTimeImmutable('2026-09-09T01:00:01Z'), 16711680);

        $embeds = $payload['embeds'] ?? null;
        self::assertIsArray($embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);
        self::assertSame('配信タイトル', $embed['title'] ?? null);
        self::assertSame('配信が開始されました。', $embed['description'] ?? null);
        $fields = $embed['fields'] ?? null;
        self::assertIsArray($fields);
        $field = $fields[0] ?? null;
        self::assertIsArray($field);
        self::assertSame('<t:1788915600:F>（<t:1788915600:R>）', $field['value'] ?? null);
        $image = $embed['image'] ?? null;
        self::assertIsArray($image);
        self::assertSame('https://i.ytimg.com/example.jpg', $image['url'] ?? null);
        self::assertSame(16711680, $embed['color'] ?? null);
    }
}
