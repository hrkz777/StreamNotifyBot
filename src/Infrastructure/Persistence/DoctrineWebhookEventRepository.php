<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineWebhookEventRepository implements WebhookEventRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(WebhookEvent $event): bool
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                INSERT IGNORE INTO webhook_events (
                    id,
                    subscription_id,
                    payload_hash,
                    payload,
                    received_at
                ) VALUES (?, ?, ?, ?, ?)
                SQL,
            [
                Uuid::fromString($event->id)->toBinary(),
                Uuid::fromString($event->subscriptionId)->toBinary(),
                $event->payloadHash(),
                $event->payload,
                $event->receivedAt->format('Y-m-d H:i:s.u'),
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
            ],
        );

        return $affectedRows === 1;
    }
}
