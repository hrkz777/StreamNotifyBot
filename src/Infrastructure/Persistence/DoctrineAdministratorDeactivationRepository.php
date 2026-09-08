<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorDeactivationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAdministratorDeactivationRepository implements AdministratorDeactivationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function deactivate(string $administratorId, DateTimeImmutable $deactivatedAt): bool
    {
        $id = Uuid::fromString($administratorId)->toBinary();
        $time = self::formatDateTime($deactivatedAt);

        return $this->connection->transactional(function (Connection $connection) use ($id, $time): bool {
            $target = $connection->fetchAssociative(
                "SELECT role FROM administrators WHERE id = ? AND status = 'active' AND disabled_at IS NULL AND deleted_at IS NULL FOR UPDATE",
                [$id],
                [ParameterType::BINARY],
            );
            if ($target === false) {
                return false;
            }

            if (($target['role'] ?? null) === 'owner') {
                $activeOwners = $connection->fetchOne(
                    "SELECT COUNT(*) FROM administrators WHERE role = 'owner' AND status = 'active' AND disabled_at IS NULL AND deleted_at IS NULL FOR UPDATE",
                );
                if (self::readCount($activeOwners) <= 1) {
                    return false;
                }
            }

            $updated = $connection->executeStatement(
                "UPDATE administrators SET status = 'disabled', disabled_at = ?, authentication_version = authentication_version + 1, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'active' AND disabled_at IS NULL AND deleted_at IS NULL",
                [$time, $time, $id],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );
            if ($updated !== 1) {
                return false;
            }

            $connection->executeStatement('UPDATE administrator_sessions SET revoked_at = ? WHERE administrator_id = ? AND revoked_at IS NULL', [$time, $id], [ParameterType::STRING, ParameterType::BINARY]);
            $connection->executeStatement('UPDATE administrator_tokens SET revoked_at = ? WHERE administrator_id = ? AND consumed_at IS NULL AND revoked_at IS NULL', [$time, $id], [ParameterType::STRING, ParameterType::BINARY]);

            return true;
        });
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function readCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('有効owner数の永続データ形式が不正です。');
    }
}
