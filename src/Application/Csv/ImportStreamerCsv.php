<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;

final readonly class ImportStreamerCsv
{
    public const int SCHEMA_VERSION = 1;
    /** @var list<string> */
    public const array HEADERS = ['schema_version', 'streamer_key', 'agency_code', 'default_language', 'name_ja', 'name_en', 'color_code', 'is_enabled', 'platform', 'registration_identifier', 'platform_account_enabled'];

    public function __construct(private StrictCsvReader $reader, private AgencyRepository $agencies)
    {
    }

    /** @return list<StreamerCsvRegistration> */
    public function preview(string $csv): array
    {
        $groups = [];
        /** @var array<string, true> $accountKeys */
        $accountKeys = [];
        foreach ($this->reader->read($csv, self::HEADERS, self::SCHEMA_VERSION) as $line => $row) {
            try {
                $key = trim($row['streamer_key']);
                if ($key === '' || mb_strlen($key) > 191) {
                    throw new InvalidArgumentException('streamer_keyの形式が不正です。');
                }
                $agency = $this->agencies->findByCode($row['agency_code']);
                if ($agency === null) {
                    throw new InvalidArgumentException('所属区分が見つかりません。');
                }
                if (!in_array($row['is_enabled'], ['0', '1'], true) || !in_array($row['platform_account_enabled'], ['0', '1'], true)) {
                    throw new InvalidArgumentException('有効状態は0または1で指定してください。');
                }
                $names = [new StreamerName(SupportedLanguage::Japanese, $row['name_ja'])];
                if (trim($row['name_en']) !== '') {
                    $names[] = new StreamerName(SupportedLanguage::English, $row['name_en']);
                }
                $input = new RegisterStreamerInput($agency->id, SupportedLanguage::fromInput($row['default_language']), trim($row['color_code']) ?: null, $row['is_enabled'] === '1', $names, Platform::from($row['platform']), $row['registration_identifier'], $row['platform_account_enabled'] === '1');
                $accountKey = $input->platform->value . "\0" . mb_strtolower(trim($input->registrationIdentifier));
                if (isset($accountKeys[$accountKey])) {
                    throw new InvalidArgumentException('同じプラットフォームアカウントを複数行に指定できません。');
                }
                $accountKeys[$accountKey] = true;
                if (!isset($groups[$key])) {
                    $groups[$key] = new StreamerCsvRegistration($key, $agency->id, $input->defaultLanguage, $input->colorCode, $input->isEnabled, $input->names, [$input]);
                    continue;
                }
                $current = $groups[$key];
                if ($current->agencyId !== $agency->id || $current->defaultLanguage !== $input->defaultLanguage || $current->colorCode !== $input->colorCode || $current->isEnabled !== $input->isEnabled || $current->names != $input->names) {
                    throw new InvalidArgumentException('同じstreamer_keyでは配信者情報を一致させてください。');
                }
                $groups[$key] = new StreamerCsvRegistration($current->key, $current->agencyId, $current->defaultLanguage, $current->colorCode, $current->isEnabled, $current->names, [...$current->accounts, $input]);
            } catch (InvalidArgumentException|\ValueError $exception) {
                throw new CsvFormatException(sprintf('%d行目: %s', $line + 2, $exception->getMessage()));
            }
        }
        return array_values($groups);
    }
}
