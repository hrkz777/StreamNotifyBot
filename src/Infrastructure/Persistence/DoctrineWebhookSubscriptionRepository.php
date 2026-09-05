<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineWebhookSubscriptionRepository implements WebhookSubscriptionRepository
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    public function add(WebhookSubscription $subscription): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO webhook_subscriptions (
                    id,
                    platform_account_id,
                    subscription_type,
                    external_subscription_id,
                    status,
                    expires_at,
                    renew_after,
                    last_attempted_at,
                    failure_count,
                    processing_lease_token,
                    processing_lease_until,
                    last_error_code,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                SQL,
            [
                Uuid::fromString($subscription->id)->toBinary(),
                Uuid::fromString($subscription->platformAccountId)->toBinary(),
                $subscription->subscriptionType,
                $subscription->externalSubscriptionId,
                $subscription->status->value,
                self::formatNullableDateTime($subscription->expiresAt),
                self::formatNullableDateTime($subscription->renewAfter),
                self::formatNullableDateTime($subscription->lastAttemptedAt),
                $subscription->failureCount,
                $subscription->processingLeaseToken === null ? null : hex2bin($subscription->processingLeaseToken),
                self::formatNullableDateTime($subscription->processingLeaseUntil),
                $subscription->lastErrorCode,
                $now,
                $now,
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    public function findById(string $id): ?WebhookSubscription
    {
        return $this->findOne('id = ?', [Uuid::fromString($id)->toBinary()], [ParameterType::BINARY]);
    }

    public function findByAccountAndType(string $platformAccountId, string $subscriptionType): ?WebhookSubscription
    {
        return $this->findOne(
            'platform_account_id = ? AND subscription_type = ?',
            [Uuid::fromString($platformAccountId)->toBinary(), $subscriptionType],
            [ParameterType::BINARY, ParameterType::STRING],
        );
    }

    public function claimDue(int $limit, string $leaseToken, int $leaseSeconds): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Webhook購読の取得件数は1件以上1000件以下で指定してください。');
        }

        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Webhook購読のリース時間は1秒以上3600秒以下で指定してください。');
        }

        $binaryLeaseToken = self::binaryLeaseToken($leaseToken);
        if ($this->connection->fetchOne(
            'SELECT 1 FROM webhook_subscriptions WHERE processing_lease_token = ? LIMIT 1',
            [$binaryLeaseToken],
            [ParameterType::BINARY],
        ) !== false) {
            throw new InvalidArgumentException('使用中のWebhook購読リーストークンは再利用できません。');
        }

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE webhook_subscriptions
                SET
                    processing_lease_token = ?,
                    processing_lease_until = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)),
                    last_attempted_at = UTC_TIMESTAMP(6),
                    updated_at = UTC_TIMESTAMP(6),
                    lock_version = lock_version + 1
                WHERE status IN ('pending', 'active')
                    AND renew_after IS NOT NULL
                    AND renew_after <= UTC_TIMESTAMP(6)
                    AND (processing_lease_until IS NULL OR processing_lease_until <= UTC_TIMESTAMP(6))
                ORDER BY renew_after, id
                LIMIT ?
                SQL,
            [$binaryLeaseToken, $leaseSeconds, $limit],
            [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id,
                    platform_account_id,
                    subscription_type,
                    external_subscription_id,
                    status,
                    expires_at,
                    renew_after,
                    last_attempted_at,
                    failure_count,
                    processing_lease_token,
                    processing_lease_until,
                    last_error_code
                FROM webhook_subscriptions
                WHERE processing_lease_token = ?
                ORDER BY renew_after, id
                SQL,
            [$binaryLeaseToken],
            [ParameterType::BINARY],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function saveClaimResult(WebhookSubscription $subscription): bool
    {
        if ($subscription->processingLeaseToken === null || $subscription->processingLeaseUntil === null) {
            throw new InvalidArgumentException('Webhook購読処理結果には取得時のリース情報が必要です。');
        }

        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE webhook_subscriptions
                SET
                    external_subscription_id = ?,
                    status = ?,
                    expires_at = ?,
                    renew_after = ?,
                    last_attempted_at = ?,
                    failure_count = ?,
                    processing_lease_token = NULL,
                    processing_lease_until = NULL,
                    last_error_code = ?,
                    updated_at = UTC_TIMESTAMP(6),
                    lock_version = lock_version + 1
                WHERE id = ? AND processing_lease_token = ?
                SQL,
            [
                $subscription->externalSubscriptionId,
                $subscription->status->value,
                self::formatNullableDateTime($subscription->expiresAt),
                self::formatNullableDateTime($subscription->renewAfter),
                self::formatNullableDateTime($subscription->lastAttemptedAt),
                $subscription->failureCount,
                $subscription->lastErrorCode,
                Uuid::fromString($subscription->id)->toBinary(),
                self::binaryLeaseToken($subscription->processingLeaseToken),
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::BINARY,
            ],
        );

        return $affectedRows === 1;
    }

    /**
     * @param list<mixed>         $parameters
     * @param list<ParameterType> $types
     */
    private function findOne(string $where, array $parameters, array $types): ?WebhookSubscription
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        id,
                        platform_account_id,
                        subscription_type,
                        external_subscription_id,
                        status,
                        expires_at,
                        renew_after,
                        last_attempted_at,
                        failure_count,
                        processing_lease_token,
                        processing_lease_until,
                        last_error_code
                    FROM webhook_subscriptions
                    WHERE %s
                    SQL,
                $where,
            ),
            $parameters,
            $types,
        );

        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): WebhookSubscription
    {
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['platform_account_id'] ?? null)
            || !is_string($row['subscription_type'] ?? null)
            || !array_key_exists('external_subscription_id', $row)
            || (!is_string($row['external_subscription_id']) && $row['external_subscription_id'] !== null)
            || !is_string($row['status'] ?? null)
            || (!is_int($row['failure_count'] ?? null) && !is_string($row['failure_count'] ?? null))
            || !array_key_exists('processing_lease_token', $row)
            || (!is_string($row['processing_lease_token']) && $row['processing_lease_token'] !== null)
            || !array_key_exists('last_error_code', $row)
            || (!is_string($row['last_error_code']) && $row['last_error_code'] !== null)
        ) {
            throw new UnexpectedValueException('Webhook購読の永続データ形式が不正です。');
        }

        return new WebhookSubscription(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            Uuid::fromBinary($row['platform_account_id'])->toRfc4122(),
            $row['subscription_type'],
            $row['external_subscription_id'],
            WebhookSubscriptionStatus::from($row['status']),
            self::parseNullableDateTime($row, 'expires_at'),
            self::parseNullableDateTime($row, 'renew_after'),
            self::parseNullableDateTime($row, 'last_attempted_at'),
            (int) $row['failure_count'],
            $row['processing_lease_token'] === null ? null : bin2hex($row['processing_lease_token']),
            self::parseNullableDateTime($row, 'processing_lease_until'),
            $row['last_error_code'],
        );
    }

    private static function formatNullableDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function binaryLeaseToken(string $leaseToken): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $leaseToken) !== 1) {
            throw new InvalidArgumentException('Webhook購読のリーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        $binaryLeaseToken = hex2bin($leaseToken);
        if ($binaryLeaseToken === false) {
            throw new InvalidArgumentException('Webhook購読のリーストークンを変換できません。');
        }

        return $binaryLeaseToken;
    }

    /** @param array<string, mixed> $row */
    private static function parseNullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException('Webhook購読の日時列が不足しています。');
        }

        if ($row[$key] === null) {
            return null;
        }

        if (!is_string($row[$key])) {
            throw new UnexpectedValueException('Webhook購読の日時形式が不正です。');
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row[$key], new DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new UnexpectedValueException('Webhook購読の日時形式が不正です。');
        }

        return $dateTime;
    }
}
