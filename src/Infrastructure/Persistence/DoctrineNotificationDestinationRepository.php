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

    public function findEnabledByType(StreamNotificationType $type): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, encrypted_webhook_url, encryption_nonce, encryption_key_id, encryption_format_version FROM notification_destinations WHERE notification_type = ? AND is_enabled = 1 ORDER BY id', [$type->value], [ParameterType::STRING]);

        return array_map(static fn (array $row): NotificationDestination => new NotificationDestination(Uuid::fromBinary(self::string($row, 'id'))->toRfc4122(), $type, new EncryptedSecret(self::string($row, 'encrypted_webhook_url'), self::string($row, 'encryption_nonce'), self::string($row, 'encryption_key_id'), self::integer($row, 'encryption_format_version')), true), $rows);
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
