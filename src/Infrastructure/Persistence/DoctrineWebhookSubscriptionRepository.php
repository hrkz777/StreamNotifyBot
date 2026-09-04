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
