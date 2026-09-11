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
use App\Infrastructure\Platform\TwitCasting\TwitCastingLiveStatusProvider;
use RuntimeException;

final readonly class SyncTwitCastingStreams implements TwitCastingStreamSynchronizer
{
    public function __construct(private StreamerCatalogRepository $streamerCatalogRepository, private TwitCastingLiveStatusProvider $liveStatusProvider, private PlatformVideoRepository $platformVideoRepository, private EnqueueStreamNotifications $enqueueStreamNotifications, private IdGenerator $idGenerator, private Clock $clock)
    {
    }

    public function sync(): int
    {
        $savedCount = 0;
        $accounts = $this->streamerCatalogRepository->findEnabledPlatformAccounts(Platform::TwitCasting);
        $liveMovieIdsByAccountId = [];
        foreach ($accounts as $account) {
            $status = $this->liveStatusProvider->fetch($account->externalId);
            if ($status->userId !== $account->externalId) {
                throw new RuntimeException('TwitCasting配信状態のユーザーIDが一致しません。');
            }
            if (!$status->isLive || $status->movieId === null || $status->title === null || $status->startedAt === null) {
                continue;
            }
            $video = new PlatformVideo($this->idGenerator->generate(), $account->id, $status->movieId, $status->title, $status->startedAt, null, $status->startedAt, null, null, 'live', $this->clock->now());
            $this->enqueueStreamNotifications->enqueue($this->platformVideoRepository->save($video), $video);
            $liveMovieIdsByAccountId[$account->id] = $status->movieId;
            ++$savedCount;
        }

        $now = $this->clock->now();
        foreach ($this->platformVideoRepository->findLiveByPlatformAccountIds(array_map(static fn ($account) => $account->id, $accounts)) as $video) {
            if (($liveMovieIdsByAccountId[$video->platformAccountId] ?? null) === $video->externalVideoId) {
                continue;
            }
            $endedVideo = new PlatformVideo($video->id, $video->platformAccountId, $video->externalVideoId, $video->title, $video->publishedAt, $video->scheduledStartAt, $video->actualStartAt, $now, $video->thumbnailUrl, 'ended', $now);
            $this->enqueueStreamNotifications->enqueue($this->platformVideoRepository->save($endedVideo), $endedVideo);
            ++$savedCount;
        }

        return $savedCount;
    }
}
