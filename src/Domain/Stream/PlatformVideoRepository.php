<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface PlatformVideoRepository
{
    /** Returns the ID of the persisted video, including an existing matching row. */
    public function save(PlatformVideo $video): string;

    public function findById(string $id): ?PlatformVideo;

    /**
     * @param list<string> $platformAccountIds
     * @return list<PlatformVideo>
     */
    public function findLiveByPlatformAccountIds(array $platformAccountIds): array;
}
