<?php

declare(strict_types=1);

namespace App\Application\Stream;

use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\Stream\NotificationDestination;
use App\Infrastructure\Notification\DiscordWebhookNotifier;

final readonly class SendDiscordNotification
{
    public function __construct(private SecretCipher $secretCipher, private DiscordWebhookNotifier $notifier)
    {
    }

    /** @param array<string, mixed> $payload */
    public function send(NotificationDestination $destination, array $payload): void
    {
        $webhookUrl = $this->secretCipher->decrypt($destination->encryptedWebhookUrl, SecretPurpose::DiscordWebhookUrl, $destination->id);
        try {
            $this->notifier->send($webhookUrl, $payload);
        } finally {
            sodium_memzero($webhookUrl);
        }
    }
}
