<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;

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

    /**
     * @param list<string> $platformAccountIds
     * @return list<PlatformVideo>
     */
    public function findUpcomingByPlatformAccountIds(array $platformAccountIds, DateTimeImmutable $from, int $limit): array;
}
