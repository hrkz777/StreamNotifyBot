<?php

declare(strict_types=1);

namespace App\Domain\System;

interface OperationalSettingRepository
{
    /** @return list<OperationalSetting> */
    public function findAll(): array;

    public function save(OperationalSetting $setting): void;

    /** @param list<OperationalSetting> $settings */
    public function saveAll(array $settings): void;
}
