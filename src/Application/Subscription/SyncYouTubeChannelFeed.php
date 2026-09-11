<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Platform\YouTube\YouTubeChannelFeedProvider;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsProvider;
use InvalidArgumentException;

final readonly class SyncYouTubeChannelFeed
{
    public function __construct(
        private StreamerCatalogRepository $streamerCatalogRepository,
        private YouTubeChannelFeedProvider $feedFetcher,
        private YouTubeVideoDetailsProvider $videoDetailsProvider,
        private PlatformVideoRepository $platformVideoRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function sync(string $platformAccountId): int
    {
        $account = $this->streamerCatalogRepository->findPlatformAccountById($platformAccountId);
        if ($account === null || !$account->isEnabled || $account->platform !== Platform::YouTube) {
            throw new InvalidArgumentException('有効なYouTubeプラットフォームアカウントが見つかりません。');
        }

        $videoIds = array_values(array_unique(array_map(
            static fn ($entry): string => $entry->videoId,
            $this->feedFetcher->fetch($account->externalId),
        )));
        $savedCount = 0;
        foreach (array_chunk($videoIds, 50) as $videoIdChunk) {
            foreach ($this->videoDetailsProvider->fetch($videoIdChunk) as $detail) {
                if ($detail->channelId !== $account->externalId) {
                    throw new InvalidArgumentException('YouTube動画詳細のチャンネルIDが一致しません。');
                }
                $this->platformVideoRepository->save(new PlatformVideo($this->idGenerator->generate(), $account->id, $detail->videoId, $detail->title, $detail->publishedAt, $detail->scheduledStartAt, $detail->actualStartAt, $detail->actualEndAt, $detail->thumbnailUrl, $detail->liveBroadcastContent, $this->clock->now()));
                ++$savedCount;
            }
        }

        return $savedCount;
    }
}
