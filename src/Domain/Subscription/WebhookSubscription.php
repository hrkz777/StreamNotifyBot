<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookSubscription
{
    public string $subscriptionType;

    public ?string $externalSubscriptionId;

    public ?DateTimeImmutable $expiresAt;

    public ?DateTimeImmutable $renewAfter;

    public ?DateTimeImmutable $lastAttemptedAt;

    public ?DateTimeImmutable $processingLeaseUntil;

    public ?string $lastErrorCode;

    public function __construct(
        public string $id,
        public string $platformAccountId,
        string $subscriptionType,
        ?string $externalSubscriptionId,
        public WebhookSubscriptionStatus $status,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $renewAfter,
        ?DateTimeImmutable $lastAttemptedAt,
        public int $failureCount,
        public ?string $processingLeaseToken,
        ?DateTimeImmutable $processingLeaseUntil,
        ?string $lastErrorCode,
    ) {
        self::assertUuidV7($id, 'Webhook購読ID');
        self::assertUuidV7($platformAccountId, 'プラットフォームアカウントID');

        if (preg_match('/^[a-z0-9._:-]{1,64}$/D', $subscriptionType) !== 1) {
            throw new InvalidArgumentException('購読種別は64文字以内の小文字ASCIIコードで指定してください。');
        }

        if (
            $externalSubscriptionId !== null
            && preg_match('/^[\x21-\x7E]{1,255}$/D', $externalSubscriptionId) !== 1
        ) {
            throw new InvalidArgumentException('外部購読IDは255文字以内の空白を含まないASCII文字列で指定してください。');
        }

        if ($failureCount < 0) {
            throw new InvalidArgumentException('連続失敗回数は0以上で指定してください。');
        }

        if (
            $processingLeaseToken !== null
            && preg_match('/^[0-9a-f]{32}$/D', $processingLeaseToken) !== 1
        ) {
            throw new InvalidArgumentException('処理リーストークンは128ビットの小文字16進文字列で指定してください。');
        }

        if (($processingLeaseToken === null) !== ($processingLeaseUntil === null)) {
            throw new InvalidArgumentException('処理リーストークンと処理リース期限は同時に指定してください。');
        }

        if ($lastErrorCode !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $lastErrorCode) !== 1) {
            throw new InvalidArgumentException('最終エラーコードの形式が不正です。');
        }

        $this->subscriptionType = $subscriptionType;
        $this->externalSubscriptionId = $externalSubscriptionId;
        $this->expiresAt = self::toNullableUtc($expiresAt);
        $this->renewAfter = self::toNullableUtc($renewAfter);
        $this->lastAttemptedAt = self::toNullableUtc($lastAttemptedAt);
        $this->processingLeaseUntil = self::toNullableUtc($processingLeaseUntil);
        $this->lastErrorCode = $lastErrorCode;

        if ($this->renewAfter !== null && $this->expiresAt !== null && $this->renewAfter > $this->expiresAt) {
            throw new InvalidArgumentException('購読更新予定日時は有効期限以前にしてください。');
        }
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }

    private static function toNullableUtc(?DateTimeImmutable $dateTime): ?DateTimeImmutable
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'));
    }
}
