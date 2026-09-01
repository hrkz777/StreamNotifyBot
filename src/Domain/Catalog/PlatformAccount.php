<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PlatformAccount
{
    public string $externalId;

    public string $registrationIdentifier;

    public ?string $displayId;

    public ?string $name;

    public ?string $profileUrl;

    public ?string $iconUrl;

    public ?string $offlineImageUrl;

    public DateTimeImmutable $resolvedAt;

    public ?DateTimeImmutable $apiDataRefreshedAt;

    public ?DateTimeImmutable $apiDataExpiresAt;

    public function __construct(
        public string $id,
        public string $streamerId,
        public Platform $platform,
        string $externalId,
        string $registrationIdentifier,
        ?string $displayId,
        ?string $name,
        ?string $profileUrl,
        ?string $iconUrl,
        ?string $offlineImageUrl,
        public bool $isEnabled,
        DateTimeImmutable $resolvedAt,
        ?DateTimeImmutable $apiDataRefreshedAt = null,
        ?DateTimeImmutable $apiDataExpiresAt = null,
    ) {
        self::assertUuidV7($id, 'プラットフォームアカウントID');
        self::assertUuidV7($streamerId, '配信者ID');

        if (preg_match('/^[\x21-\x7E]{1,255}$/D', $externalId) !== 1) {
            throw new InvalidArgumentException('外部IDは255文字以内の空白を含まないASCII文字列で指定してください。');
        }

        $this->externalId = $externalId;
        $this->registrationIdentifier = self::normalizeText($registrationIdentifier, 255, '登録識別子', false);
        $this->displayId = self::normalizeOptionalText($displayId, 255, '表示ID');
        $this->name = self::normalizeOptionalText($name, 191, 'プラットフォーム上の名称');
        $this->profileUrl = self::normalizeOptionalHttpsUrl($profileUrl, 'プロフィールURL');
        $this->iconUrl = self::normalizeOptionalHttpsUrl($iconUrl, 'アイコンURL');
        $this->offlineImageUrl = self::normalizeOptionalHttpsUrl($offlineImageUrl, 'オフライン画像URL');
        $this->resolvedAt = self::toUtc($resolvedAt);
        $this->apiDataRefreshedAt = $apiDataRefreshedAt === null ? null : self::toUtc($apiDataRefreshedAt);
        $this->apiDataExpiresAt = $apiDataExpiresAt === null ? null : self::toUtc($apiDataExpiresAt);

        if (
            $this->apiDataRefreshedAt !== null
            && $this->apiDataExpiresAt !== null
            && $this->apiDataExpiresAt < $this->apiDataRefreshedAt
        ) {
            throw new InvalidArgumentException('APIデータの期限は最終更新日時以降にしてください。');
        }
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }

    private static function normalizeOptionalText(?string $value, int $maxLength, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalizedValue = self::normalizeText($value, $maxLength, $label, true);

        return $normalizedValue === '' ? null : $normalizedValue;
    }

    private static function normalizeText(string $value, int $maxLength, string $label, bool $allowEmpty): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('%sはUTF-8で指定してください。', $label));
        }

        $normalizedValue = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value)
            ?? throw new InvalidArgumentException(sprintf('%sを正規化できませんでした。', $label));

        if (
            (!$allowEmpty && $normalizedValue === '')
            || mb_strlen($normalizedValue) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/u', $normalizedValue) === 1
        ) {
            throw new InvalidArgumentException(sprintf('%sの形式が不正です。', $label));
        }

        return $normalizedValue;
    }

    private static function normalizeOptionalHttpsUrl(?string $url, string $label): ?string
    {
        $normalizedUrl = self::normalizeOptionalText($url, 2048, $label);
        if ($normalizedUrl === null) {
            return null;
        }

        $parts = parse_url($normalizedUrl);
        if (
            filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException(sprintf('%sは認証情報を含まないHTTPS URLで指定してください。', $label));
        }

        return $normalizedUrl;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
