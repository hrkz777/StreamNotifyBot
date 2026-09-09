<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Application\Stream\EnqueueStreamNotifications;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Infrastructure\Platform\TwitCasting\TwitCastingLiveStatusFetcher;

final readonly class SyncTwitCastingStreams
{
    public function __construct(private StreamerCatalogRepository $streamerCatalogRepository, private TwitCastingLiveStatusFetcher $liveStatusFetcher, private PlatformVideoRepository $platformVideoRepository, private EnqueueStreamNotifications $enqueueStreamNotifications, private IdGenerator $idGenerator, private Clock $clock)
    {
    }

    public function sync(): int
    {
        $savedCount = 0;
        foreach ($this->streamerCatalogRepository->findEnabledPlatformAccounts(Platform::TwitCasting) as $account) {
            $status = $this->liveStatusFetcher->fetch($account->externalId);
            if (!$status->isLive || $status->movieId === null || $status->title === null || $status->startedAt === null) {
                continue;
            }
            $video = new PlatformVideo($this->idGenerator->generate(), $account->id, $status->movieId, $status->title, $status->startedAt, null, $status->startedAt, null, null, 'live', $this->clock->now());
            $this->enqueueStreamNotifications->enqueue($this->platformVideoRepository->save($video), $video);
            ++$savedCount;
        }

        return $savedCount;
    }
}
