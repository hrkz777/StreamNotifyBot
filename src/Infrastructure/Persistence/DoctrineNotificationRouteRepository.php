<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Notification\NotificationRoute;
use App\Domain\Notification\NotificationRouteConfiguration;
use App\Domain\Notification\NotificationRouteRepository;
use App\Domain\Notification\NotificationRouteWebhook;
use App\Domain\Notification\NotificationEventType;
use App\Domain\Security\EncryptedSecret;
use App\Domain\System\Clock;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineNotificationRouteRepository implements NotificationRouteRepository
{
    public function __construct(private Connection $connection, private Clock $clock)
    {
    }

    public function add(NotificationRoute $route): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(
            'INSERT INTO notification_routes (id, name, description, color, is_enabled, created_at, updated_at, lock_version) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [Uuid::fromString($route->id)->toBinary(), $route->name, $route->description, $route->color, $route->isEnabled ? 1 : 0, $now, $now],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function saveConfiguration(NotificationRouteConfiguration $configuration): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->transactional(function (Connection $connection) use ($configuration, $now): void {
            $route = $configuration->route;
            $routeId = Uuid::fromString($route->id)->toBinary();
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO notification_routes (id, name, description, color, is_enabled, created_at, updated_at, lock_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), color = VALUES(color), is_enabled = VALUES(is_enabled), updated_at = VALUES(updated_at), lock_version = lock_version + 1
                    SQL,
                [$routeId, $route->name, $route->description, $route->color, $route->isEnabled ? 1 : 0, $now, $now],
                [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
            );
            $connection->executeStatement('DELETE FROM notification_route_webhooks WHERE notification_route_id = ?', [$routeId], [ParameterType::BINARY]);
            foreach ($configuration->webhooks as $webhook) {
                $connection->executeStatement(
                    'INSERT INTO notification_route_webhooks (id, notification_route_id, event_type, encrypted_value, encryption_nonce, encryption_key_id, encryption_format_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [Uuid::fromString($webhook->id)->toBinary(), $routeId, $webhook->eventType->value, $webhook->encryptedUrl->encryptedValue, $webhook->encryptedUrl->nonce, $webhook->encryptedUrl->keyId, $webhook->encryptedUrl->formatVersion, $now, $now],
                    [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
                );
            }
            $connection->executeStatement('DELETE FROM notification_route_streamers WHERE notification_route_id = ?', [$routeId], [ParameterType::BINARY]);
            foreach ($configuration->streamerIds as $streamerId) {
                $inserted = $connection->executeStatement(
                    'INSERT INTO notification_route_streamers (notification_route_id, streamer_id, created_at) SELECT ?, id, ? FROM streamers WHERE id = ?',
                    [$routeId, $now, Uuid::fromString($streamerId)->toBinary()],
                    [ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY],
                );
                if ($inserted !== 1) {
                    throw new \InvalidArgumentException('通知対象の配信者が見つかりません。');
                }
            }
        });
    }

    public function findConfiguration(string $routeId): ?NotificationRouteConfiguration
    {
        $route = $this->connection->fetchAssociative('SELECT id, name, description, color, is_enabled FROM notification_routes WHERE id = ?', [Uuid::fromString($routeId)->toBinary()], [ParameterType::BINARY]);
        if ($route === false) {
            return null;
        }
        $webhooks = array_map(static fn (array $row): NotificationRouteWebhook => self::hydrateWebhook($row, $routeId), $this->connection->fetchAllAssociative('SELECT id, event_type, encrypted_value, encryption_nonce, encryption_key_id, encryption_format_version, created_at, updated_at FROM notification_route_webhooks WHERE notification_route_id = ?', [Uuid::fromString($routeId)->toBinary()], [ParameterType::BINARY]));
        $streamerIds = array_map(static function (mixed $id): string {
            if (!is_string($id)) {
                throw new UnexpectedValueException('通知対象配信者の永続データ形式が不正です。');
            }

            return Uuid::fromBinary($id)->toRfc4122();
        }, $this->connection->fetchFirstColumn('SELECT streamer_id FROM notification_route_streamers WHERE notification_route_id = ?', [Uuid::fromString($routeId)->toBinary()], [ParameterType::BINARY]));

        return new NotificationRouteConfiguration(self::hydrate($route), $webhooks, $streamerIds);
    }

    public function remove(string $routeId): void
    {
        $deleted = $this->connection->executeStatement('DELETE FROM notification_routes WHERE id = ?', [Uuid::fromString($routeId)->toBinary()], [ParameterType::BINARY]);
        if ($deleted !== 1) {
            throw new \InvalidArgumentException('削除対象の通知設定が見つかりません。');
        }
    }

    /** @return list<NotificationRoute> */
    public function findAll(): array
    {
        return array_map(self::hydrate(...), $this->connection->fetchAllAssociative('SELECT id, name, description, color, is_enabled FROM notification_routes ORDER BY name, id'));
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): NotificationRoute
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['name'] ?? null) || !array_key_exists('description', $row) || (!is_string($row['description']) && $row['description'] !== null) || !is_string($row['color'] ?? null) || (!is_string($row['is_enabled'] ?? null) && !is_int($row['is_enabled'] ?? null))) {
            throw new UnexpectedValueException('通知設定の永続データ形式が不正です。');
        }
        return new NotificationRoute(Uuid::fromBinary($row['id'])->toRfc4122(), $row['name'], $row['description'], $row['color'], $row['is_enabled'] === 1 || $row['is_enabled'] === '1');
    }

    /** @param array<string, mixed> $row */
    private static function hydrateWebhook(array $row, string $routeId): NotificationRouteWebhook
    {
        foreach (['id', 'event_type', 'encrypted_value', 'encryption_nonce', 'encryption_key_id', 'created_at', 'updated_at'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new UnexpectedValueException('通知設定Webhookの永続データ形式が不正です。');
            }
        }
        $version = $row['encryption_format_version'] ?? null;
        if ((!is_int($version) && !is_string($version)) || filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new UnexpectedValueException('通知設定Webhookの永続データ形式が不正です。');
        }

        return new NotificationRouteWebhook(Uuid::fromBinary($row['id'])->toRfc4122(), $routeId, NotificationEventType::from($row['event_type']), new EncryptedSecret($row['encrypted_value'], $row['encryption_nonce'], $row['encryption_key_id'], (int) $version), new \DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')), new \DateTimeImmutable($row['updated_at'], new DateTimeZone('UTC')));
    }
}
