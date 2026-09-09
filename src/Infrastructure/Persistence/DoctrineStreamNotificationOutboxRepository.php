<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStreamNotificationOutboxRepository implements StreamNotificationOutboxRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function enqueue(StreamNotificationOutbox $notification): void
    {
        $hash = hex2bin($notification->payloadHash);
        if ($hash === false) {
            throw new \InvalidArgumentException('通知内容ハッシュを変換できません。');
        }
        $occurredAt = $notification->occurredAt->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO stream_notification_outbox (id, platform_video_id, notification_type, payload_hash, status, occurred_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = IF(payload_hash <> VALUES(payload_hash), 'pending', status),
                payload_hash = VALUES(payload_hash), occurred_at = VALUES(occurred_at),
                updated_at = VALUES(updated_at), lock_version = lock_version + 1
            SQL, [Uuid::fromString($notification->id)->toBinary(), Uuid::fromString($notification->platformVideoId)->toBinary(), $notification->type->value, $hash, $occurredAt, $occurredAt, $occurredAt], [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING]);
    }
}
