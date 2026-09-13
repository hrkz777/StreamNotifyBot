<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use InvalidArgumentException;

final readonly class NotificationRoute
{
    public string $name;
    public ?string $description;

    public function __construct(
        public string $id,
        string $name,
        ?string $description,
        public string $color,
        public bool $isEnabled,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('通知設定IDは小文字標準形式のUUIDv7で指定してください。');
        }
        $this->name = self::normalize($name, 100, '通知設定名');
        $this->description = $description === null || trim($description) === '' ? null : self::normalize($description, 200, '説明');
        if (!in_array($color, ['purple', 'blue', 'pink', 'orange'], true)) {
            throw new InvalidArgumentException('通知設定の表示色が不正です。');
        }
    }

    private static function normalize(string $value, int $maximumLength, string $label): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($label.'はUTF-8で指定してください。');
        }
        $normalized = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
        if ($normalized === null || $normalized === '' || mb_strlen($normalized) > $maximumLength || preg_match('/[\x00-\x1F\x7F]/u', $normalized) === 1) {
            throw new InvalidArgumentException($label.'の形式が不正です。');
        }

        return $normalized;
    }
}
