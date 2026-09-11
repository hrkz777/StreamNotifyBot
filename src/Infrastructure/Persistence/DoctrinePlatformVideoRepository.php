<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePlatformVideoRepository implements PlatformVideoRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(PlatformVideo $video): string
    {
        $observedAt = $video->observedAt->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_videos (
                    id, platform_account_id, external_video_id, title, published_at,
                    scheduled_start_at, actual_start_at, actual_end_at, thumbnail_url,
                    lifecycle_state, first_observed_at, last_observed_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title), published_at = VALUES(published_at),
                    scheduled_start_at = VALUES(scheduled_start_at), actual_start_at = VALUES(actual_start_at),
                    actual_end_at = VALUES(actual_end_at), thumbnail_url = VALUES(thumbnail_url),
                    lifecycle_state = VALUES(lifecycle_state), last_observed_at = VALUES(last_observed_at),
                    updated_at = VALUES(updated_at), lock_version = lock_version + 1
                SQL,
            [
                Uuid::fromString($video->id)->toBinary(), Uuid::fromString($video->platformAccountId)->toBinary(),
                $video->externalVideoId, $video->title, $video->publishedAt->format('Y-m-d H:i:s.u'),
                $video->scheduledStartAt?->format('Y-m-d H:i:s.u'), $video->actualStartAt?->format('Y-m-d H:i:s.u'),
                $video->actualEndAt?->format('Y-m-d H:i:s.u'), $video->thumbnailUrl, $video->lifecycleState,
                $observedAt, $observedAt, $observedAt, $observedAt,
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING],
        );

        $id = $this->connection->fetchOne(
            'SELECT id FROM platform_videos WHERE platform_account_id = ? AND external_video_id = ?',
            [Uuid::fromString($video->platformAccountId)->toBinary(), $video->externalVideoId],
            [ParameterType::BINARY, ParameterType::STRING],
        );
        if (!is_string($id)) {
            throw new \LogicException('保存済みプラットフォーム動画IDを取得できません。');
        }

        return Uuid::fromBinary($id)->toRfc4122();
    }

    public function findById(string $id): ?PlatformVideo
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT id, platform_account_id, external_video_id, title, published_at,
                scheduled_start_at, actual_start_at, actual_end_at, thumbnail_url,
                lifecycle_state, last_observed_at
            FROM platform_videos
            WHERE id = ?
            SQL, [Uuid::fromString($id)->toBinary()], [ParameterType::BINARY]);
        if ($row === false) {
            return null;
        }

        return self::platformVideo($row);
    }

    /**
     * @param list<string> $platformAccountIds
     * @return list<PlatformVideo>
     */
    public function findLiveByPlatformAccountIds(array $platformAccountIds): array
    {
        if ($platformAccountIds === []) {
            return [];
        }

        $ids = [];
        foreach ($platformAccountIds as $platformAccountId) {
            $ids[] = Uuid::fromString($platformAccountId)->toBinary();
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, platform_account_id, external_video_id, title, published_at,
                scheduled_start_at, actual_start_at, actual_end_at, thumbnail_url,
                lifecycle_state, last_observed_at
            FROM platform_videos
            WHERE lifecycle_state = 'live' AND platform_account_id IN (?)
            SQL, [$ids], [ArrayParameterType::BINARY]);

        return array_map(self::platformVideo(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function platformVideo(array $row): PlatformVideo
    {
        return new PlatformVideo(
            Uuid::fromBinary(self::binaryColumn($row, 'id'))->toRfc4122(),
            Uuid::fromBinary(self::binaryColumn($row, 'platform_account_id'))->toRfc4122(),
            self::stringColumn($row, 'external_video_id'),
            self::stringColumn($row, 'title'),
            self::dateTimeColumn($row, 'published_at'),
            self::nullableDateTimeColumn($row, 'scheduled_start_at'),
            self::nullableDateTimeColumn($row, 'actual_start_at'),
            self::nullableDateTimeColumn($row, 'actual_end_at'),
            self::nullableStringColumn($row, 'thumbnail_url'),
            self::stringColumn($row, 'lifecycle_state'),
            self::dateTimeColumn($row, 'last_observed_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function binaryColumn(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new \InvalidArgumentException('プラットフォーム動画の永続データ形式が不正です。');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function stringColumn(array $row, string $key): string
    {
        return self::binaryColumn($row, $key);
    }

    /** @param array<string, mixed> $row */
    private static function nullableStringColumn(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('プラットフォーム動画の永続データ形式が不正です。');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function dateTimeColumn(array $row, string $key): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', self::stringColumn($row, $key), new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \InvalidArgumentException('プラットフォーム動画の日時形式が不正です。');
        }

        return $dateTime;
    }

    /** @param array<string, mixed> $row */
    private static function nullableDateTimeColumn(array $row, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableStringColumn($row, $key);
        if ($value === null) {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \InvalidArgumentException('プラットフォーム動画の日時形式が不正です。');
        }

        return $dateTime;
    }
}
