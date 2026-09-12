<?php

declare(strict_types=1);

namespace App\Domain\System;

use InvalidArgumentException;

final class OperationalSettingCatalog
{
    /** @var array<string, OperationalSettingDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, OperationalSettingDefinition> */
    public static function all(): array
    {
        return self::$definitions ??= self::buildDefinitions();
    }

    public static function get(string $key): OperationalSettingDefinition
    {
        $definition = self::all()[$key] ?? null;
        if (!$definition instanceof OperationalSettingDefinition) {
            throw new InvalidArgumentException('運用設定のキーが不正です。');
        }

        return $definition;
    }

    /** @return array<string, OperationalSettingDefinition> */
    private static function buildDefinitions(): array
    {
        $definitions = [];
        foreach (['scheduled', 'imminent', 'live', 'ended', 'error'] as $state) {
            foreach (['youtube', 'twitch', 'twitcasting'] as $platform) {
                $key = "polling_{$state}_{$platform}";
                $definitions[$key] = new OperationalSettingDefinition($key, 60, 604800);
            }
        }
        foreach (['youtube', 'twitch', 'twitcasting'] as $platform) {
            foreach (['allocation' => 1, 'normal' => 1, 'reserved' => 0] as $kind => $minimum) {
                $key = "quota_{$platform}_{$kind}";
                $definitions[$key] = new OperationalSettingDefinition($key, $minimum, 2147483647);
            }
        }
        foreach ([
            'retention_delivery_results' => [7, 30],
            'retention_api_data_after_notification' => [1, 14],
            'retention_audit_logs' => [90, 3650],
            'retention_deleted_masters' => [1, 365],
        ] as $key => [$minimum, $maximum]) {
            $definitions[$key] = new OperationalSettingDefinition($key, $minimum, $maximum);
        }

        return $definitions;
    }
}
