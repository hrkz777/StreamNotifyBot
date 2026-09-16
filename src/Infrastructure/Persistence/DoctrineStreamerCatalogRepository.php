<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\System\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineStreamerCatalogRepository implements StreamerCatalogRepository
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
        private DoctrineWebhookSubscriptionRepository $webhookSubscriptionRepository,
    ) {
    }

    /** @param iterable<WebhookSubscription> $initialSubscriptions */
    public function register(
        Streamer $streamer,
        PlatformAccount $initialAccount,
        iterable $initialSubscriptions = [],
    ): void {
        if ($streamer->id !== $initialAccount->streamerId) {
            throw new InvalidArgumentException('初期プラットフォームアカウントは登録対象の配信者に属する必要があります。');
        }

        $subscriptions = array_values([...$initialSubscriptions]);
        foreach ($subscriptions as $subscription) {
            if ($subscription->platformAccountId !== $initialAccount->id) {
                throw new InvalidArgumentException('初期Webhook購読は登録対象のプラットフォームアカウントに属する必要があります。');
            }
        }

        $this->registerWithAccounts($streamer, [$initialAccount], $subscriptions);
    }

    /**
     * @param iterable<int, PlatformAccount> $accounts
     * @param iterable<int, WebhookSubscription> $subscriptions
     */
    public function registerWithAccounts(Streamer $streamer, iterable $accounts, iterable $subscriptions): void
    {
        /** @var list<PlatformAccount> $accountItems */
        $accountItems = [...$accounts];
        if ($accountItems === []) {
            throw new InvalidArgumentException('配信者には少なくとも1件のプラットフォームアカウントが必要です。');
        }
        $accountIds = [];
        foreach ($accountItems as $account) {
            if ($account->streamerId !== $streamer->id || isset($accountIds[$account->id])) {
                throw new InvalidArgumentException('プラットフォームアカウントの登録内容が不正です。');
            }
            $accountIds[$account->id] = true;
        }
        /** @var list<WebhookSubscription> $subscriptionItems */
        $subscriptionItems = [...$subscriptions];
        foreach ($subscriptionItems as $subscription) {
            if (!isset($accountIds[$subscription->platformAccountId])) {
                throw new InvalidArgumentException('Webhook購読は登録対象のプラットフォームアカウントに属する必要があります。');
            }
        }
        $now = $this->formattedNow();
        $this->connection->transactional(function (Connection $connection) use ($streamer, $accountItems, $subscriptionItems, $now): void {
            $this->insertStreamer($connection, $streamer, $now);
            foreach ($accountItems as $account) {
                $this->insertPlatformAccount($connection, $account, $now);
            }
            foreach ($subscriptionItems as $subscription) {
                $this->webhookSubscriptionRepository->add($subscription);
            }
        });
    }

    public function updateStreamer(Streamer $streamer): void
    {
        $now = $this->formattedNow();
        $this->connection->transactional(function (Connection $connection) use ($streamer, $now): void {
            $streamerId = Uuid::fromString($streamer->id)->toBinary();
            $updated = $connection->executeStatement(
                <<<'SQL'
                    UPDATE streamers
                    SET agency_id = ?, default_language_code = ?, color_code = ?, is_enabled = ?, updated_at = ?, lock_version = lock_version + 1
                    WHERE id = ?
                    SQL,
                [
                    Uuid::fromString($streamer->agencyId)->toBinary(),
                    $streamer->defaultLanguage->value,
                    $streamer->colorCode,
                    $streamer->isEnabled ? 1 : 0,
                    $now,
                    $streamerId,
                ],
                [
                    ParameterType::BINARY,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::BINARY,
                ],
            );
            if ($updated !== 1) {
                throw new InvalidArgumentException('更新対象の配信者が見つかりません。');
            }

            $connection->executeStatement('DELETE FROM streamer_names WHERE streamer_id = ?', [$streamerId], [ParameterType::BINARY]);
            foreach ($streamer->names() as $name) {
                $connection->executeStatement(
                    'INSERT INTO streamer_names (streamer_id, language_code, name, created_at, updated_at, lock_version) VALUES (?, ?, ?, ?, ?, 0)',
                    [$streamerId, $name->language->value, $name->name, $now, $now],
                    [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
            }
        });
    }

    public function addPlatformAccount(PlatformAccount $account): void
    {
        $this->insertPlatformAccount($this->connection, $account, $this->formattedNow());
    }

    public function addPlatformAccountWithSubscriptions(PlatformAccount $account, iterable $subscriptions): void
    {
        $subscriptionItems = [...$subscriptions];
        foreach ($subscriptionItems as $subscription) {
            if ($subscription->platformAccountId !== $account->id) {
                throw new InvalidArgumentException('Webhook購読は追加するプラットフォームアカウントに属する必要があります。');
            }
        }
        $now = $this->formattedNow();
        $this->connection->transactional(function (Connection $connection) use ($account, $subscriptionItems, $now): void {
            $this->insertPlatformAccount($connection, $account, $now);
            foreach ($subscriptionItems as $subscription) {
                $this->webhookSubscriptionRepository->add($subscription);
            }
        });
    }

    public function removePlatformAccount(string $streamerId, string $platformAccountId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($streamerId, $platformAccountId): void {
            $streamerBinaryId = Uuid::fromString($streamerId)->toBinary();
            $accountBinaryId = Uuid::fromString($platformAccountId)->toBinary();
            $accountExists = $connection->fetchOne(
                'SELECT id FROM platform_accounts WHERE id = ? AND streamer_id = ? FOR UPDATE',
                [$accountBinaryId, $streamerBinaryId],
                [ParameterType::BINARY, ParameterType::BINARY],
            );
            if ($accountExists === false) {
                throw new InvalidArgumentException('削除対象のプラットフォームアカウントが見つかりません。');
            }

            $hasVideos = $connection->fetchOne(
                'SELECT id FROM platform_videos WHERE platform_account_id = ? LIMIT 1 FOR UPDATE',
                [$accountBinaryId],
                [ParameterType::BINARY],
            );
            if ($hasVideos !== false) {
                throw new \App\Application\Catalog\PlatformAccountCannotBeRemoved('配信履歴があるプラットフォームアカウントは削除できません。停止して履歴を保持してください。');
            }

            $connection->executeStatement('DELETE FROM webhook_subscriptions WHERE platform_account_id = ?', [$accountBinaryId], [ParameterType::BINARY]);
            $connection->executeStatement('DELETE FROM platform_accounts WHERE id = ? AND streamer_id = ?', [$accountBinaryId, $streamerBinaryId], [ParameterType::BINARY, ParameterType::BINARY]);
        });
    }

    public function findStreamerById(string $id): ?Streamer
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, agency_id, default_language_code, color_code, is_enabled
                FROM streamers
                WHERE id = ?
                SQL,
            [Uuid::fromString($id)->toBinary()],
            [ParameterType::BINARY],
        );

        return $row === false ? null : $this->hydrateStreamer($row);
    }

    /** @return list<Streamer> */
    public function findAllStreamers(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, agency_id, default_language_code, color_code, is_enabled
                FROM streamers
                ORDER BY id
                SQL,
        );

        return array_map($this->hydrateStreamer(...), $rows);
    }

    /** @return list<PlatformAccount> */
    public function findAllPlatformAccounts(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, streamer_id, platform_code, external_id, registration_identifier, display_id, name, profile_url, icon_url, offline_image_url, is_enabled, resolved_at, api_data_refreshed_at, api_data_expires_at
            FROM platform_accounts
            ORDER BY streamer_id, platform_code, id
            SQL);

        return array_map(self::hydratePlatformAccount(...), $rows);
    }

    public function findPlatformAccountById(string $id): ?PlatformAccount
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id,
                    streamer_id,
                    platform_code,
                    external_id,
                    registration_identifier,
                    display_id,
                    name,
                    profile_url,
                    icon_url,
                    offline_image_url,
                    is_enabled,
                    resolved_at,
                    api_data_refreshed_at,
                    api_data_expires_at
                FROM platform_accounts
                WHERE id = ?
                SQL,
            [Uuid::fromString($id)->toBinary()],
            [ParameterType::BINARY],
        );

        return $row === false ? null : self::hydratePlatformAccount($row);
    }

    public function findPlatformAccountByExternalId(Platform $platform, string $externalId): ?PlatformAccount
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id,
                    streamer_id,
                    platform_code,
                    external_id,
                    registration_identifier,
                    display_id,
                    name,
                    profile_url,
                    icon_url,
                    offline_image_url,
                    is_enabled,
                    resolved_at,
                    api_data_refreshed_at,
                    api_data_expires_at
                FROM platform_accounts
                WHERE platform_code = ? AND external_id = ?
                SQL,
            [$platform->value, $externalId],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $row === false ? null : self::hydratePlatformAccount($row);
    }

    private function insertStreamer(Connection $connection, Streamer $streamer, string $now): void
    {
        $binaryId = Uuid::fromString($streamer->id)->toBinary();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO streamers (
                    id,
                    agency_id,
                    default_language_code,
                    color_code,
                    is_enabled,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                SQL,
            [
                $binaryId,
                Uuid::fromString($streamer->agencyId)->toBinary(),
                $streamer->defaultLanguage->value,
                $streamer->colorCode,
                $streamer->isEnabled ? 1 : 0,
                $now,
                $now,
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );

        foreach ($streamer->names() as $name) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO streamer_names (
                        streamer_id,
                        language_code,
                        name,
                        created_at,
                        updated_at,
                        lock_version
                    ) VALUES (?, ?, ?, ?, ?, 0)
                    SQL,
                [
                    $binaryId,
                    $name->language->value,
                    $name->name,
                    $now,
                    $now,
                ],
                [
                    ParameterType::BINARY,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            );
        }
    }

    private function insertPlatformAccount(Connection $connection, PlatformAccount $account, string $now): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_accounts (
                    id,
                    streamer_id,
                    platform_code,
                    external_id,
                    registration_identifier,
                    display_id,
                    name,
                    profile_url,
                    icon_url,
                    offline_image_url,
                    is_enabled,
                    resolved_at,
                    api_data_refreshed_at,
                    api_data_expires_at,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                SQL,
            [
                Uuid::fromString($account->id)->toBinary(),
                Uuid::fromString($account->streamerId)->toBinary(),
                $account->platform->value,
                $account->externalId,
                $account->registrationIdentifier,
                $account->displayId,
                $account->name,
                $account->profileUrl,
                $account->iconUrl,
                $account->offlineImageUrl,
                $account->isEnabled ? 1 : 0,
                self::formatDateTime($account->resolvedAt),
                self::formatNullableDateTime($account->apiDataRefreshedAt),
                self::formatNullableDateTime($account->apiDataExpiresAt),
                $now,
                $now,
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateStreamer(array $row): Streamer
    {
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['agency_id'] ?? null)
            || !is_string($row['default_language_code'] ?? null)
            || !array_key_exists('color_code', $row)
            || (!is_string($row['color_code']) && $row['color_code'] !== null)
            || (!is_int($row['is_enabled'] ?? null) && !is_string($row['is_enabled'] ?? null))
        ) {
            throw new UnexpectedValueException('配信者の永続データ形式が不正です。');
        }

        $nameRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT language_code, name
                FROM streamer_names
                WHERE streamer_id = ?
                ORDER BY language_code
                SQL,
            [$row['id']],
            [ParameterType::BINARY],
        );

        return new Streamer(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            Uuid::fromBinary($row['agency_id'])->toRfc4122(),
            SupportedLanguage::from($row['default_language_code']),
            $row['color_code'],
            $row['is_enabled'] === 1 || $row['is_enabled'] === '1',
            array_map(self::hydrateStreamerName(...), $nameRows),
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrateStreamerName(array $row): StreamerName
    {
        if (!is_string($row['language_code'] ?? null) || !is_string($row['name'] ?? null)) {
            throw new UnexpectedValueException('配信者名の永続データ形式が不正です。');
        }

        return new StreamerName(SupportedLanguage::from($row['language_code']), $row['name']);
    }

    /** @param array<string, mixed> $row */
    private static function hydratePlatformAccount(array $row): PlatformAccount
    {
        $requiredStringKeys = [
            'id',
            'streamer_id',
            'platform_code',
            'external_id',
            'registration_identifier',
            'resolved_at',
        ];
        foreach ($requiredStringKeys as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new UnexpectedValueException('プラットフォームアカウントの永続データ形式が不正です。');
            }
        }

        $nullableStringKeys = [
            'display_id',
            'name',
            'profile_url',
            'icon_url',
            'offline_image_url',
            'api_data_refreshed_at',
            'api_data_expires_at',
        ];
        foreach ($nullableStringKeys as $key) {
            if (!array_key_exists($key, $row) || (!is_string($row[$key]) && $row[$key] !== null)) {
                throw new UnexpectedValueException('プラットフォームアカウントの永続データ形式が不正です。');
            }
        }

        if (!is_int($row['is_enabled'] ?? null) && !is_string($row['is_enabled'] ?? null)) {
            throw new UnexpectedValueException('プラットフォームアカウントの永続データ形式が不正です。');
        }

        return new PlatformAccount(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            Uuid::fromBinary($row['streamer_id'])->toRfc4122(),
            Platform::from($row['platform_code']),
            $row['external_id'],
            $row['registration_identifier'],
            $row['display_id'],
            $row['name'],
            $row['profile_url'],
            $row['icon_url'],
            $row['offline_image_url'],
            $row['is_enabled'] === 1 || $row['is_enabled'] === '1',
            new DateTimeImmutable($row['resolved_at'], new DateTimeZone('UTC')),
            self::hydrateNullableDateTime($row['api_data_refreshed_at']),
            self::hydrateNullableDateTime($row['api_data_expires_at']),
        );
    }

    private function formattedNow(): string
    {
        return self::formatDateTime($this->clock->now());
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private static function formatNullableDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime === null ? null : self::formatDateTime($dateTime);
    }

    private static function hydrateNullableDateTime(?string $dateTime): ?DateTimeImmutable
    {
        return $dateTime === null ? null : new DateTimeImmutable($dateTime, new DateTimeZone('UTC'));
    }
}
