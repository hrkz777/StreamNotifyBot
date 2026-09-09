<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use App\Domain\Security\EncryptedSecret;
use InvalidArgumentException;

final readonly class NotificationDestination
{
    public function __construct(public string $id, public StreamNotificationType $notificationType, public EncryptedSecret $encryptedWebhookUrl, public bool $isEnabled)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('通知先IDは小文字標準形式のUUIDv7で指定してください。');
        }
    }
}
