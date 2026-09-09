<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Stream\StreamNotificationOutbox;
use App\Domain\Stream\StreamNotificationOutboxLease;
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

    public function claimPending(int $limit, string $leaseToken, int $leaseSeconds): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('通知アウトボックスの取得件数は1件以上1000件以下で指定してください。');
        }
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('通知アウトボックスのリース時間は1秒以上3600秒以下で指定してください。');
        }

        $binaryLeaseToken = self::binaryLeaseToken($leaseToken);
        $this->connection->executeStatement(<<<'SQL'
            UPDATE stream_notification_outbox
            SET processing_lease_token = ?, processing_lease_until = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))
            WHERE status = 'pending'
                AND (processing_lease_until IS NULL OR processing_lease_until <= UTC_TIMESTAMP(6))
            ORDER BY occurred_at, id
            LIMIT ?
            SQL, [$binaryLeaseToken, $leaseSeconds, $limit], [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER]);
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, platform_video_id, notification_type, payload_hash, occurred_at, processing_lease_until
            FROM stream_notification_outbox
            WHERE processing_lease_token = ? AND status = 'pending'
            ORDER BY occurred_at, id
            SQL, [$binaryLeaseToken], [ParameterType::BINARY]);

        return array_map(
            static fn (array $row): StreamNotificationOutboxLease => new StreamNotificationOutboxLease(
                new StreamNotificationOutbox(
                    Uuid::fromBinary(self::binaryColumn($row, 'id'))->toRfc4122(),
                    Uuid::fromBinary(self::binaryColumn($row, 'platform_video_id'))->toRfc4122(),
                    \App\Domain\Stream\StreamNotificationType::from(self::stringColumn($row, 'notification_type')),
                    bin2hex(self::binaryColumn($row, 'payload_hash')),
                    self::dateTimeColumn($row, 'occurred_at'),
                ),
                $leaseToken,
                self::dateTimeColumn($row, 'processing_lease_until'),
            ),
            $rows,
        );
    }

    public function markSent(StreamNotificationOutboxLease $lease): bool
    {
        return $this->finishClaim($lease, 'sent_at = UTC_TIMESTAMP(6), status = \'sent\'');
    }

    public function releaseClaim(StreamNotificationOutboxLease $lease): bool
    {
        return $this->finishClaim($lease, '');
    }

    private function finishClaim(StreamNotificationOutboxLease $lease, string $stateSql): bool
    {
        $setState = $stateSql === '' ? '' : sprintf('%s, ', $stateSql);
        return $this->connection->executeStatement(
            sprintf('UPDATE stream_notification_outbox SET %sprocessing_lease_token = NULL, processing_lease_until = NULL, updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ? AND status = \'pending\' AND processing_lease_token = ?', $setState),
            [Uuid::fromString($lease->notification->id)->toBinary(), self::binaryLeaseToken($lease->token)],
            [ParameterType::BINARY, ParameterType::BINARY],
        ) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function binaryColumn(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new \InvalidArgumentException('通知アウトボックスの永続データ形式が不正です。');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function stringColumn(array $row, string $key): string
    {
        return self::binaryColumn($row, $key);
    }

    /** @param array<string, mixed> $row */
    private static function dateTimeColumn(array $row, string $key): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', self::binaryColumn($row, $key), new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \InvalidArgumentException('通知アウトボックスの日時形式が不正です。');
        }

        return $dateTime;
    }

    private static function binaryLeaseToken(string $leaseToken): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $leaseToken) !== 1) {
            throw new \InvalidArgumentException('通知アウトボックスの処理リーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        return hex2bin($leaseToken) ?: throw new \InvalidArgumentException('通知アウトボックスの処理リーストークンを変換できません。');
    }
}
