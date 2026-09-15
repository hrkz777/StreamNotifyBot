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

    /**
     * @param iterable<int, PlatformAccount> $accounts
     * @param iterable<int, WebhookSubscription> $subscriptions
     */
    public function registerWithAccounts(Streamer $streamer, iterable $accounts, iterable $subscriptions): void;

    public function addPlatformAccount(PlatformAccount $account): void;

    /** @param iterable<WebhookSubscription> $subscriptions */
    public function addPlatformAccountWithSubscriptions(PlatformAccount $account, iterable $subscriptions): void;

    public function findStreamerById(string $id): ?Streamer;

    /** @return list<Streamer> */
    public function findAllStreamers(): array;

    /** @return list<PlatformAccount> */
    public function findAllPlatformAccounts(): array;

    public function findPlatformAccountById(string $id): ?PlatformAccount;

    public function findPlatformAccountByExternalId(Platform $platform, string $externalId): ?PlatformAccount;
}
