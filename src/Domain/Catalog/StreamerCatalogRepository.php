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

    public function findPlatformAccountById(string $id): ?PlatformAccount;

    public function findPlatformAccountByExternalId(Platform $platform, string $externalId): ?PlatformAccount;
}
