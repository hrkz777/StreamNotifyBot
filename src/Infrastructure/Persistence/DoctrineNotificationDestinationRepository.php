<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Security\EncryptedSecret;
use App\Domain\Stream\NotificationDestination;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Domain\Stream\StreamNotificationType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineNotificationDestinationRepository implements NotificationDestinationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(NotificationDestination $destination): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO notification_destinations (
                    id, notification_type, encrypted_webhook_url, encryption_nonce,
                    encryption_key_id, encryption_format_version, is_enabled, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    notification_type = VALUES(notification_type), encrypted_webhook_url = VALUES(encrypted_webhook_url),
                    encryption_nonce = VALUES(encryption_nonce), encryption_key_id = VALUES(encryption_key_id),
                    encryption_format_version = VALUES(encryption_format_version), is_enabled = VALUES(is_enabled),
                    updated_at = VALUES(updated_at), lock_version = lock_version + 1
                SQL,
            [
                Uuid::fromString($destination->id)->toBinary(), $destination->notificationType->value,
                $destination->encryptedWebhookUrl->encryptedValue, $destination->encryptedWebhookUrl->nonce,
                $destination->encryptedWebhookUrl->keyId, $destination->encryptedWebhookUrl->formatVersion,
                $destination->isEnabled ? 1 : 0, $now, $now,
            ],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY,
                ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function findEnabledByType(StreamNotificationType $type): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, encrypted_webhook_url, encryption_nonce, encryption_key_id, encryption_format_version FROM notification_destinations WHERE notification_type = ? AND is_enabled = 1 ORDER BY id', [$type->value], [ParameterType::STRING]);

        return array_map(static fn (array $row): NotificationDestination => new NotificationDestination(Uuid::fromBinary(self::string($row, 'id'))->toRfc4122(), $type, new EncryptedSecret(self::string($row, 'encrypted_webhook_url'), self::string($row, 'encryption_nonce'), self::string($row, 'encryption_key_id'), self::integer($row, 'encryption_format_version')), true), $rows);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, notification_type, encrypted_webhook_url, encryption_nonce, encryption_key_id, encryption_format_version, is_enabled FROM notification_destinations ORDER BY notification_type, id');

        return array_map(static fn (array $row): NotificationDestination => new NotificationDestination(Uuid::fromBinary(self::string($row, 'id'))->toRfc4122(), StreamNotificationType::from(self::string($row, 'notification_type')), new EncryptedSecret(self::string($row, 'encrypted_webhook_url'), self::string($row, 'encryption_nonce'), self::string($row, 'encryption_key_id'), self::integer($row, 'encryption_format_version')), self::integer($row, 'is_enabled') === 1), $rows);
    }

    public function countDestinations(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM notification_destinations');
        if (!is_int($count) && !(is_string($count) && preg_match('/^[0-9]+$/D', $count) === 1)) {
            throw new \UnexpectedValueException('通知先件数の永続データ形式が不正です。');
        }

        return (int) $count;
    }

    public function setEnabled(string $id, bool $isEnabled): bool
    {
        return $this->connection->executeStatement('UPDATE notification_destinations SET is_enabled = ?, updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?', [$isEnabled ? 1 : 0, Uuid::fromString($id)->toBinary()], [ParameterType::INTEGER, ParameterType::BINARY]) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new \UnexpectedValueException('通知先の永続データ形式が不正です。');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) ? $row[$key] : (is_numeric($row[$key] ?? null) ? (int) $row[$key] : throw new \UnexpectedValueException('通知先の永続データ形式が不正です。'));
    }
}
