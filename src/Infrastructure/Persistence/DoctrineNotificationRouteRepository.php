<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Notification\NotificationRoute;
use App\Domain\Notification\NotificationRouteRepository;
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
}
