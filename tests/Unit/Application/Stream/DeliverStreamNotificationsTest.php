<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stream;

use App\Application\Stream\DeliverStreamNotifications;
use App\Application\Stream\DeliverStreamNotificationsInput;
use App\Application\Stream\SendDiscordNotification;
use App\Domain\Security\SecretCipher;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationOutboxLease;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\Stream\StreamNotificationType;
use App\Domain\System\Clock;
use App\Domain\System\LeaseTokenGenerator;
use App\Infrastructure\Notification\DiscordStreamNotificationPayloadFactory;
use App\Infrastructure\Notification\DiscordWebhookNotifier;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DeliverStreamNotificationsTest extends TestCase
{
    #[Test]
    public function itSuppressesAClaimWhenThePlatformVideoNoLongerExists(): void
    {
        $lease = new StreamNotificationOutboxLease(
            new StreamNotificationOutbox('01990d4a-0000-7000-8000-000000000701', '01990d4a-0000-7000-8000-000000000702', StreamNotificationType::VideoPublished, str_repeat('a', 64), new DateTimeImmutable('2026-09-09 00:00:00+00:00')),
            '00112233445566778899aabbccddeeff',
            new DateTimeImmutable('2026-09-09 00:02:00+00:00'),
        );
        $outbox = $this->createMock(StreamNotificationOutboxRepository::class);
        $outbox->expects(self::once())->method('claimPending')->with(10, 'ffeeddccbbaa99887766554433221100', 120)->willReturn([$lease]);
        $outbox->expects(self::once())->method('suppress')->with($lease)->willReturn(true);
        $videos = $this->createMock(PlatformVideoRepository::class);
        $videos->expects(self::once())->method('findById')->with($lease->notification->platformVideoId)->willReturn(null);
        $destinations = $this->createMock(NotificationDestinationRepository::class);
        $destinations->expects(self::never())->method('findEnabledByType');
        $tokens = $this->createStub(LeaseTokenGenerator::class);
        $tokens->method('generate')->willReturn('ffeeddccbbaa99887766554433221100');

        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::never())->method('decrypt');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $result = (new DeliverStreamNotifications($outbox, $videos, $destinations, new DiscordStreamNotificationPayloadFactory(), new SendDiscordNotification($cipher, new DiscordWebhookNotifier($httpClient)), $tokens, $this->clock()))->deliver(new DeliverStreamNotificationsInput(10, 120));

        self::assertSame(1, $result->claimedCount);
        self::assertSame(1, $result->suppressedCount);
        self::assertSame(0, $result->sentCount);
    }

    private function clock(): Clock
    {
        return new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-09 00:00:00', new DateTimeZone('UTC'));
            }
        };
    }
}
