<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class AuditLog
{
    private const int MAX_CHANGE_SUMMARY_BYTES = 65_535;

    public ?string $changeSummary;

    /** @param array<array-key, mixed>|null $changeSummary */
    public function __construct(
        public string $id,
        public DateTimeImmutable $occurredAt,
        public ?string $actorAdministratorId,
        public ?string $actorDisplaySnapshot,
        public string $actionCode,
        public ?string $targetType,
        public ?string $targetId,
        public AuditLogResult $result,
        public string $correlationId,
        public ?string $sourceIp,
        public ?string $userAgent,
        ?array $changeSummary,
        public ?string $errorCode,
    ) {
        self::assertUuidV7($id, '監査ログID');
        if ($actorAdministratorId !== null) {
            self::assertUuidV7($actorAdministratorId, '操作者管理者ID');
        }

        self::assertOptionalDisplayText($actorDisplaySnapshot, 191, '操作者表示スナップショット');
        self::assertCode($actionCode, 64, '操作コード');
        self::assertOptionalCode($targetType, 64, '対象種別');
        self::assertOptionalAsciiText($targetId, 191, '対象ID');
        if (($targetType === null) !== ($targetId === null)) {
            throw new InvalidArgumentException('監査ログの対象種別と対象IDは同時に指定してください。');
        }

        self::assertAsciiText($correlationId, 64, '相関ID');
        if ($sourceIp !== null && filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('送信元IPの形式が不正です。');
        }

        self::assertOptionalDisplayText($userAgent, 512, 'User-Agent');
        self::assertOptionalCode($errorCode, 64, 'エラーコード');
        $this->changeSummary = self::encodeChangeSummary($changeSummary);
    }

    public static function soleOwnerCredentialRecoverySucceeded(
        string $id,
        string $correlationId,
        string $administratorId,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            id: $id,
            occurredAt: $occurredAt,
            actorAdministratorId: null,
            actorDisplaySnapshot: null,
            actionCode: 'administrator.sole_owner_credentials_recovered',
            targetType: 'administrator',
            targetId: $administratorId,
            result: AuditLogResult::Succeeded,
            correlationId: $correlationId,
            sourceIp: null,
            userAgent: null,
            changeSummary: [
                'credential_types' => ['password', 'totp', 'recovery_codes'],
                'authentication_version_incremented' => true,
                'sessions_revoked' => true,
                'tokens_revoked' => true,
            ],
            errorCode: null,
        );
    }

    /** @param array<array-key, mixed>|null $changeSummary */
    private static function encodeChangeSummary(?array $changeSummary): ?string
    {
        if ($changeSummary === null) {
            return null;
        }

        if ($changeSummary !== [] && array_is_list($changeSummary)) {
            throw new InvalidArgumentException('監査ログの変更要約はJSONオブジェクトとして指定してください。');
        }

        self::assertJsonValue($changeSummary, 0);

        try {
            $encoded = json_encode(
                (object) $changeSummary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('監査ログの変更要約をJSONへ変換できません。', previous: $exception);
        }

        if (strlen($encoded) > self::MAX_CHANGE_SUMMARY_BYTES) {
            throw new InvalidArgumentException('監査ログの変更要約は65535バイト以下で指定してください。');
        }

        return $encoded;
    }

    private static function assertJsonValue(mixed $value, int $depth): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('監査ログの変更要約が深すぎます。');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value) && is_finite($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJsonValue($item, $depth + 1);
            }

            return;
        }

        throw new InvalidArgumentException('監査ログの変更要約にJSON化できない値が含まれています。');
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }

    private static function assertCode(string $value, int $maxLength, string $label): void
    {
        if (strlen($value) > $maxLength || preg_match('/^[a-z0-9][a-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%sの形式が不正です。', $label));
        }
    }

    private static function assertOptionalCode(?string $value, int $maxLength, string $label): void
    {
        if ($value !== null) {
            self::assertCode($value, $maxLength, $label);
        }
    }

    private static function assertAsciiText(string $value, int $maxLength, string $label): void
    {
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[^\x20-\x7e]/D', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%sの形式が不正です。', $label));
        }
    }

    private static function assertOptionalAsciiText(?string $value, int $maxLength, string $label): void
    {
        if ($value !== null) {
            self::assertAsciiText($value, $maxLength, $label);
        }
    }

    private static function assertOptionalDisplayText(?string $value, int $maxLength, string $label): void
    {
        if (
            $value !== null
            && (
                $value === ''
                || mb_strlen($value) > $maxLength
                || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1
            )
        ) {
            throw new InvalidArgumentException(sprintf('%sの形式が不正です。', $label));
        }
    }
}
