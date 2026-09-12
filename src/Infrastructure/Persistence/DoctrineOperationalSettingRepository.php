<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\System\OperationalSetting;
use App\Domain\System\OperationalSettingRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use UnexpectedValueException;

final readonly class DoctrineOperationalSettingRepository implements OperationalSettingRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findAll(): array
    {
        return array_map(self::hydrate(...), $this->connection->fetchAllAssociative('SELECT setting_key, setting_value, updated_at, lock_version FROM operational_settings ORDER BY setting_key'));
    }

    public function save(OperationalSetting $setting): void
    {
        $updated = $this->connection->executeStatement('UPDATE operational_settings SET setting_value = ?, updated_at = ?, lock_version = lock_version + 1 WHERE setting_key = ? AND lock_version = ?', [$setting->value, $setting->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $setting->key, $setting->lockVersion], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]);
        if ($updated !== 1) {
            throw new UnexpectedValueException('運用設定の更新が競合しました。');
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): OperationalSetting
    {
        if (!is_string($row['setting_key'] ?? null) || !is_string($row['setting_value'] ?? null) || !is_string($row['updated_at'] ?? null) || !is_string($row['lock_version'] ?? null) || preg_match('/^[0-9]+$/D', $row['setting_value']) !== 1 || preg_match('/^[0-9]+$/D', $row['lock_version']) !== 1) {
            throw new UnexpectedValueException('運用設定の永続データ形式が不正です。');
        }
        $updatedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['updated_at'], new DateTimeZone('UTC'));
        if ($updatedAt === false) {
            throw new UnexpectedValueException('運用設定の更新日時が不正です。');
        }

        return new OperationalSetting($row['setting_key'], (int) $row['setting_value'], $updatedAt, (int) $row['lock_version']);
    }
}
