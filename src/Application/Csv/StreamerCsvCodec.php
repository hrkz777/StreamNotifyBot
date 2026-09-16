<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\SupportedLanguage;

final readonly class StreamerCsvCodec
{
    /** @var list<string> */
    public const array HEADERS = [
        'streamer_id', 'agency_id', 'default_language', 'name_ja', 'name_en', 'color_code', 'is_enabled',
        'platform_account_id', 'platform', 'external_id', 'registration_identifier', 'display_id', 'platform_name', 'platform_account_enabled',
    ];

    /**
     * @param iterable<int, Streamer> $streamers
     * @param iterable<int, PlatformAccount> $accounts
     */
    public function export(iterable $streamers, iterable $accounts): string
    {
        /** @var array<string, list<PlatformAccount>> $accountsByStreamer */
        $accountsByStreamer = [];
        foreach ($accounts as $account) {
            $accountsByStreamer[$account->streamerId][] = $account;
        }

        $rows = [];
        $recordLabels = [];
        foreach ($streamers as $streamer) {
            $streamerAccounts = $accountsByStreamer[$streamer->id] ?? [];
            if ($streamerAccounts === []) {
                $rows[] = self::row($streamer, null);
                $recordLabels[] = self::recordLabel($streamer);
                continue;
            }
            foreach ($streamerAccounts as $account) {
                $rows[] = self::row($streamer, $account);
                $recordLabels[] = self::recordLabel($streamer);
            }
        }

        return CsvEncoder::encode(self::HEADERS, $rows, $recordLabels);
    }

    /** @return list<string> */
    private static function row(Streamer $streamer, ?PlatformAccount $account): array
    {
        $streamerValues = [
            $streamer->id, $streamer->agencyId, $streamer->defaultLanguage->value,
            $streamer->nameFor(SupportedLanguage::Japanese)->name, $streamer->nameFor(SupportedLanguage::English)->name,
            $streamer->colorCode ?? '', $streamer->isEnabled ? '1' : '0',
        ];
        if ($account === null) {
            return [...$streamerValues, '', '', '', '', '', '', ''];
        }

        return [
            ...$streamerValues,
            $account->id, $account->platform->value, $account->externalId,
            $account->registrationIdentifier, $account->displayId ?? '', $account->name ?? '',
            $account->isEnabled ? '1' : '0',
        ];
    }

    private static function recordLabel(Streamer $streamer): string
    {
        return sprintf('配信者「%s」', $streamer->nameFor(SupportedLanguage::Japanese)->name);
    }
}
