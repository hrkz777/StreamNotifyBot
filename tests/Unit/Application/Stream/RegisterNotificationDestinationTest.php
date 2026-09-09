<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stream;

use App\Application\Stream\RegisterNotificationDestination;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\Stream\NotificationDestination;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Domain\Stream\StreamNotificationType;
use App\Domain\System\IdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterNotificationDestinationTest extends TestCase
{
    private const DESTINATION_ID = '01990d4a-0000-7000-8000-000000000801';

    #[Test]
    public function itEncryptsAndPersistsAValidDiscordWebhookUrl(): void
    {
        $repository = $this->createMock(NotificationDestinationRepository::class);
        $repository->expects(self::once())->method('save')->with(self::callback(static fn (NotificationDestination $destination): bool => $destination->id === self::DESTINATION_ID && $destination->notificationType === StreamNotificationType::Started && $destination->isEnabled));
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::once())->method('encrypt')->with('https://discord.com/api/webhooks/123456789012345678/token_value', SecretPurpose::DiscordWebhookUrl, self::DESTINATION_ID)->willReturn(new EncryptedSecret(str_repeat('a', 16), str_repeat('b', 24), 'test-key'));
        $ids = $this->createStub(IdGenerator::class);
        $ids->method('generate')->willReturn(self::DESTINATION_ID);

        $id = (new RegisterNotificationDestination($repository, $cipher, $ids))->register(StreamNotificationType::Started, 'https://discord.com/api/webhooks/123456789012345678/token_value');

        self::assertSame(self::DESTINATION_ID, $id);
    }

    #[Test]
    public function itRejectsAnInvalidDiscordWebhookUrlBeforeEncryptingIt(): void
    {
        $repository = $this->createMock(NotificationDestinationRepository::class);
        $repository->expects(self::never())->method('save');
        $cipher = $this->createMock(SecretCipher::class);
        $cipher->expects(self::never())->method('encrypt');
        $ids = $this->createMock(IdGenerator::class);
        $ids->expects(self::never())->method('generate');

        $this->expectException(\InvalidArgumentException::class);
        (new RegisterNotificationDestination($repository, $cipher, $ids))->register(StreamNotificationType::Started, 'http://discord.com/api/webhooks/123/token');
    }
}
