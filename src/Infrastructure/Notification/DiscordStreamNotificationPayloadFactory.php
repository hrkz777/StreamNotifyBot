<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\StreamNotificationType;
use DateTimeImmutable;

final class DiscordStreamNotificationPayloadFactory
{
    /** @return array<string, mixed> */
    public function create(StreamNotificationType $type, PlatformVideo $video, DateTimeImmutable $createdAt, ?int $color = null): array
    {
        $time = $video->actualEndAt ?? $video->actualStartAt ?? $video->scheduledStartAt ?? $video->publishedAt;
        $embed = [
            'title' => $video->title,
            'description' => $this->message($type),
            'timestamp' => $createdAt->format(DATE_ATOM),
            'fields' => [[
                'name' => $this->timeLabel($type),
                'value' => sprintf('<t:%d:F>（<t:%d:R>）', $time->getTimestamp(), $time->getTimestamp()),
                'inline' => false,
            ]],
        ];
        if ($video->thumbnailUrl !== null) {
            $embed['image'] = ['url' => $video->thumbnailUrl];
        }
        if ($color !== null && $color >= 0 && $color <= 16777215) {
            $embed['color'] = $color;
        }

        return ['embeds' => [$embed]];
    }

    private function message(StreamNotificationType $type): string
    {
        return match ($type) {
            StreamNotificationType::VideoPublished => '動画が投稿されました。',
            StreamNotificationType::WaitingRoomCreated => '配信の待機所が作成されました。',
            StreamNotificationType::StartingInThirtyMinutes => '配信開始30分前です。',
            StreamNotificationType::ScheduledStart => '配信開始予定時刻です。',
            StreamNotificationType::Started => '配信が開始されました。',
            StreamNotificationType::Ended => '配信が終了しました。',
        };
    }

    private function timeLabel(StreamNotificationType $type): string
    {
        return $type === StreamNotificationType::Ended ? '終了時刻' : ($type === StreamNotificationType::Started ? '開始時刻' : '時刻');
    }
}
