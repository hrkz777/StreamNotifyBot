<?php

declare(strict_types=1);

namespace App\Application\Stream;

use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\Stream\NotificationDestination;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Domain\Stream\StreamNotificationType;
use App\Domain\System\IdGenerator;
use InvalidArgumentException;

final readonly class RegisterNotificationDestination
{
    public function __construct(private NotificationDestinationRepository $repository, private SecretCipher $secretCipher, private IdGenerator $idGenerator)
    {
    }

    public function register(StreamNotificationType $notificationType, #[\SensitiveParameter] string $webhookUrl, bool $isEnabled = true): string
    {
        self::assertDiscordWebhookUrl($webhookUrl);
        $id = $this->idGenerator->generate();
        try {
            $this->repository->save(new NotificationDestination(
                $id,
                $notificationType,
                $this->secretCipher->encrypt($webhookUrl, SecretPurpose::DiscordWebhookUrl, $id),
                $isEnabled,
            ));
        } finally {
            sodium_memzero($webhookUrl);
        }

        return $id;
    }

    private static function assertDiscordWebhookUrl(string $webhookUrl): void
    {
        $parts = parse_url($webhookUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'discord.com' || !is_string($parts['path'] ?? null) || preg_match('#^/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$#D', $parts['path']) !== 1 || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) {
            throw new InvalidArgumentException('Discord Webhook URLの形式が不正です。');
        }
    }
}
