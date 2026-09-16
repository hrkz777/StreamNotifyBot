<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\StreamerCatalogRepository;
use InvalidArgumentException;

final readonly class RemovePlatformAccountFromStreamer
{
    public function __construct(private StreamerCatalogRepository $streamers)
    {
    }

    public function remove(string $streamerId, string $platformAccountId): void
    {
        $account = $this->streamers->findPlatformAccountById($platformAccountId);
        if ($account === null || $account->streamerId !== $streamerId) {
            throw new InvalidArgumentException('削除対象のプラットフォームアカウントが見つかりません。画面を更新して選び直してください。');
        }

        $this->streamers->removePlatformAccount($streamerId, $platformAccountId);
    }
}
