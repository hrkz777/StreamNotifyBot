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
use App\Infrastructure\Platform\Twitch\TwitchStreamStatusProvider;

final readonly class SyncTwitchStreams implements TwitchStreamSynchronizer
{
    public function __construct(private StreamerCatalogRepository $streamerCatalogRepository, private TwitchStreamStatusProvider $streamStatusProvider, private PlatformVideoRepository $platformVideoRepository, private EnqueueStreamNotifications $enqueueStreamNotifications, private IdGenerator $idGenerator, private Clock $clock)
    {
    }

    public function sync(): int
    {
        $accounts = $this->streamerCatalogRepository->findEnabledPlatformAccounts(Platform::Twitch);
        $byExternalId = [];
        foreach ($accounts as $account) {
            $byExternalId[$account->externalId] = $account;
        }
        $savedCount = 0;
        $liveStreamIdsByAccountId = [];
        foreach (array_chunk(array_keys($byExternalId), 100) as $userIds) {
            foreach ($this->streamStatusProvider->fetch($userIds) as $status) {
                if (!$status->isLive || $status->streamId === null || $status->title === null || $status->startedAt === null) {
                    continue;
                }
                $account = $byExternalId[$status->userId] ?? null;
                if ($account === null) {
                    continue;
                }
                $video = new PlatformVideo($this->idGenerator->generate(), $account->id, $status->streamId, $status->title, $status->startedAt, null, $status->startedAt, null, $status->thumbnailUrl, 'live', $this->clock->now());
                $this->enqueueStreamNotifications->enqueue($this->platformVideoRepository->save($video), $video);
                $liveStreamIdsByAccountId[$account->id] = $status->streamId;
                ++$savedCount;
            }
        }

        $now = $this->clock->now();
        foreach ($accounts as $account) {
            $this->streamerCatalogRepository->recordPolled($account->id, $now);
        }
        foreach ($this->platformVideoRepository->findLiveByPlatformAccountIds(array_map(static fn ($account) => $account->id, $accounts)) as $video) {
            if (($liveStreamIdsByAccountId[$video->platformAccountId] ?? null) === $video->externalVideoId) {
                continue;
            }
            $endedVideo = new PlatformVideo($video->id, $video->platformAccountId, $video->externalVideoId, $video->title, $video->publishedAt, $video->scheduledStartAt, $video->actualStartAt, $now, $video->thumbnailUrl, 'ended', $now);
            $this->enqueueStreamNotifications->enqueue($this->platformVideoRepository->save($endedVideo), $endedVideo);
            ++$savedCount;
        }

        return $savedCount;
    }
}
