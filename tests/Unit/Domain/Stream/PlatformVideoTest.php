<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stream;

use App\Domain\Stream\PlatformVideo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformVideoTest extends TestCase
{
    #[Test]
    public function itNormalizesAllTimestampsToUtc(): void
    {
        $video = new PlatformVideo(
            '01990d4a-0000-7000-8000-000000000601',
            '01990d4a-0000-7000-8000-000000000602',
            'abcdefghijk',
            '配信タイトル',
            new DateTimeImmutable('2026-09-09T09:00:00+09:00'),
            null,
            null,
            null,
            'https://i.ytimg.com/example.jpg',
            'upcoming',
            new DateTimeImmutable('2026-09-09T09:00:00+09:00'),
        );

        self::assertSame('2026-09-09T00:00:00+00:00', $video->publishedAt->format(DATE_ATOM));
        self::assertSame('2026-09-09T00:00:00+00:00', $video->observedAt->format(DATE_ATOM));
    }
}
