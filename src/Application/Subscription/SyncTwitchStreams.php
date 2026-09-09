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

final readonly class SyncTwitchStreams
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
                ++$savedCount;
            }
        }

        return $savedCount;
    }
}
