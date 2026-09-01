<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface StreamerCatalogRepository
{
    public function register(Streamer $streamer, PlatformAccount $initialAccount): void;

    public function addPlatformAccount(PlatformAccount $account): void;

    public function findStreamerById(string $id): ?Streamer;

    public function findPlatformAccountById(string $id): ?PlatformAccount;

    public function findPlatformAccountByExternalId(Platform $platform, string $externalId): ?PlatformAccount;
}
