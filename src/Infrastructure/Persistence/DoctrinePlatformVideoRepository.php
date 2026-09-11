<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use Doctrine\DBAL\Connection;
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
}
