<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\System\OperationalSetting;
use App\Domain\System\OperationalSettingRepository;
use App\Domain\System\ConcurrentOperationalSettingUpdate;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
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
        $this->saveOne($setting);
    }

    public function saveAll(array $settings): void
    {
        $keys = array_map(static fn (OperationalSetting $setting): string => $setting->key, $settings);
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('運用設定に重複したキーがあります。');
        }

        $this->connection->transactional(function () use ($settings): void {
            foreach ($settings as $setting) {
                $this->saveOne($setting);
            }
        });
    }

    private function saveOne(OperationalSetting $setting): void
    {
        $updated = $this->connection->executeStatement('UPDATE operational_settings SET setting_value = ?, updated_at = ?, lock_version = lock_version + 1 WHERE setting_key = ? AND lock_version = ?', [$setting->value, $setting->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), $setting->key, $setting->lockVersion], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]);
        if ($updated !== 1) {
            throw new ConcurrentOperationalSettingUpdate();
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): OperationalSetting
    {
        if (!is_string($row['setting_key'] ?? null) || !is_string($row['updated_at'] ?? null)) {
            throw new UnexpectedValueException('運用設定の永続データ形式が不正です。');
        }
        $updatedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row['updated_at'], new DateTimeZone('UTC'));
        if ($updatedAt === false) {
            throw new UnexpectedValueException('運用設定の更新日時が不正です。');
        }

        return new OperationalSetting(
            $row['setting_key'],
            self::unsignedInteger($row['setting_value'] ?? null),
            $updatedAt,
            self::unsignedInteger($row['lock_version'] ?? null),
        );
    }

    private static function unsignedInteger(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException('運用設定の永続データ形式が不正です。');
    }
}
