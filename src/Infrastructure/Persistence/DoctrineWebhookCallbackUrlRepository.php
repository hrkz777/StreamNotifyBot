<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Subscription\WebhookCallbackUrl;
use App\Domain\Subscription\WebhookCallbackUrlRepository;
use App\Domain\System\Clock;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use UnexpectedValueException;

final readonly class DoctrineWebhookCallbackUrlRepository implements WebhookCallbackUrlRepository
{
    public function __construct(private Connection $connection, private Clock $clock)
    {
    }

    public function find(): ?WebhookCallbackUrl
    {
        $value = $this->connection->fetchOne('SELECT callback_url FROM webhook_callback_urls WHERE id = 1');
        if ($value === false) {
            return null;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('Webhook公開URLの永続データ形式が不正です。');
        }

        return new WebhookCallbackUrl($value);
    }

    public function save(WebhookCallbackUrl $url): void
    {
        $this->connection->executeStatement(
            'INSERT INTO webhook_callback_urls (id, callback_url, updated_at) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE callback_url = VALUES(callback_url), updated_at = VALUES(updated_at), lock_version = lock_version + 1',
            [$url->value, $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }
}
