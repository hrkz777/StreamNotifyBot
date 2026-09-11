<?php

declare(strict_types=1);

namespace App\Domain\Stream;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PlatformVideo
{
    public DateTimeImmutable $publishedAt;
    public ?DateTimeImmutable $scheduledStartAt;
    public ?DateTimeImmutable $actualStartAt;
    public ?DateTimeImmutable $actualEndAt;
    public DateTimeImmutable $observedAt;

    public function __construct(
        public string $id,
        public string $platformAccountId,
        public string $externalVideoId,
        public string $title,
        DateTimeImmutable $publishedAt,
        ?DateTimeImmutable $scheduledStartAt,
        ?DateTimeImmutable $actualStartAt,
        ?DateTimeImmutable $actualEndAt,
        public ?string $thumbnailUrl,
        public string $lifecycleState,
        DateTimeImmutable $observedAt,
    ) {
        foreach (['動画ID' => $id, 'プラットフォームアカウントID' => $platformAccountId] as $label => $value) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
            }
        }
        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $externalVideoId) !== 1 || $title === '' || mb_strlen($title) > 255 || !in_array($lifecycleState, ['none', 'upcoming', 'live', 'ended'], true)) {
            throw new InvalidArgumentException('プラットフォーム動画の値が不正です。');
        }
        if ($thumbnailUrl !== null && (filter_var($thumbnailUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($thumbnailUrl, 'https://') || mb_strlen($thumbnailUrl) > 2048)) {
            throw new InvalidArgumentException('サムネイルURLは2048文字以内のHTTPS URLで指定してください。');
        }
        $this->publishedAt = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $this->scheduledStartAt = $scheduledStartAt?->setTimezone(new DateTimeZone('UTC'));
        $this->actualStartAt = $actualStartAt?->setTimezone(new DateTimeZone('UTC'));
        $this->actualEndAt = $actualEndAt?->setTimezone(new DateTimeZone('UTC'));
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
