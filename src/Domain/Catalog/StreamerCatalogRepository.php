<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Subscription\WebhookSubscription;

interface StreamerCatalogRepository
{
    /** @param iterable<WebhookSubscription> $initialSubscriptions */
    public function register(
        Streamer $streamer,
        PlatformAccount $initialAccount,
        iterable $initialSubscriptions = [],
    ): void;

    public function addPlatformAccount(PlatformAccount $account): void;

    public function findStreamerById(string $id): ?Streamer;

    /** @return list<Streamer> */
    public function findAllStreamers(): array;

    public function countStreamers(): int;

    public function findPlatformAccountById(string $id): ?PlatformAccount;

    public function findPlatformAccountByExternalId(Platform $platform, string $externalId): ?PlatformAccount;

    /** @return list<PlatformAccount> */
    public function findPlatformAccountsByStreamerId(string $streamerId): array;

    /** @return list<PlatformAccount> */
    public function findEnabledPlatformAccounts(Platform $platform): array;
}
