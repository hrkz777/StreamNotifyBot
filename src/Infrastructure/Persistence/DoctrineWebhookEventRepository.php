<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Subscription\WebhookEvent;
use App\Domain\Subscription\WebhookEventLease;
use App\Domain\Subscription\WebhookEventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
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

    public function claimPending(int $limit, string $leaseToken, int $leaseSeconds): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Webhookイベントの取得件数は1件以上1000件以下で指定してください。');
        }

        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Webhookイベントのリース時間は1秒以上3600秒以下で指定してください。');
        }

        $binaryLeaseToken = self::binaryLeaseToken($leaseToken);
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE webhook_events
                SET processing_lease_token = ?, processing_lease_until = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))
                WHERE processed_at IS NULL
                    AND (processing_lease_until IS NULL OR processing_lease_until <= UTC_TIMESTAMP(6))
                ORDER BY received_at, id
                LIMIT ?
                SQL,
            [$binaryLeaseToken, $leaseSeconds, $limit],
            [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, subscription_id, payload, received_at, processing_lease_until
                FROM webhook_events
                WHERE processing_lease_token = ?
                ORDER BY received_at, id
                SQL,
            [$binaryLeaseToken],
            [ParameterType::BINARY],
        );

        return array_map(
            static fn (array $row): WebhookEventLease => new WebhookEventLease(
                new WebhookEvent(
                    Uuid::fromBinary(self::binaryColumn($row, 'id'))->toRfc4122(),
                    Uuid::fromBinary(self::binaryColumn($row, 'subscription_id'))->toRfc4122(),
                    self::binaryColumn($row, 'payload'),
                    self::dateTimeColumn($row, 'received_at'),
                ),
                $leaseToken,
                self::dateTimeColumn($row, 'processing_lease_until'),
            ),
            $rows,
        );
    }

    public function markProcessed(WebhookEventLease $lease): bool
    {
        return $this->finishClaim($lease, 'processed_at = UTC_TIMESTAMP(6)');
    }

    public function releaseClaim(WebhookEventLease $lease): bool
    {
        return $this->finishClaim($lease, '');
    }

    private function finishClaim(WebhookEventLease $lease, string $processedAtSql): bool
    {
        $setProcessedAt = $processedAtSql === '' ? '' : sprintf('%s, ', $processedAtSql);
        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'UPDATE webhook_events SET %sprocessing_lease_token = NULL, processing_lease_until = NULL WHERE id = ? AND processing_lease_token = ?',
                $setProcessedAt,
            ),
            [
                Uuid::fromString($lease->event->id)->toBinary(),
                self::binaryLeaseToken($lease->token),
            ],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        return $affectedRows === 1;
    }

    /** @param array<string, mixed> $row */
    private static function binaryColumn(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new InvalidArgumentException('Webhookイベントの永続データ形式が不正です。');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function dateTimeColumn(array $row, string $key): \DateTimeImmutable
    {
        $value = self::binaryColumn($row, $key);
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new InvalidArgumentException('Webhookイベントの日時形式が不正です。');
        }

        return $dateTime;
    }

    private static function binaryLeaseToken(string $leaseToken): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $leaseToken) !== 1) {
            throw new InvalidArgumentException('Webhookイベントの処理リーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        return hex2bin($leaseToken) ?: throw new InvalidArgumentException('Webhookイベントの処理リーストークンを変換できません。');
    }
}
