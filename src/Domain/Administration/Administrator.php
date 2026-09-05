<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Administrator
{
    public string $loginId;
    public string $displayName;

    public function __construct(
        public string $id,
        string $loginId,
        string $displayName,
        public AdministratorRole $role,
        public AdministratorStatus $status,
        public ?string $passwordHash,
        public int $authenticationVersion,
        public ?DateTimeImmutable $passwordChangedAt,
        public ?DateTimeImmutable $totpEnrolledAt,
        public ?DateTimeImmutable $lastLoginAt,
        public ?DateTimeImmutable $disabledAt,
        public ?DateTimeImmutable $deletedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public int $lockVersion,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('管理者IDは小文字標準形式のUUIDv7で指定してください。');
        }

        $normalizedLoginId = strtolower(trim($loginId));
        if (preg_match('/^[a-z0-9._-]{3,64}$/D', $normalizedLoginId) !== 1) {
            throw new InvalidArgumentException('ログインIDの形式が不正です。');
        }

        $normalizedDisplayName = trim($displayName);
        if (
            $normalizedDisplayName === ''
            || mb_strlen($normalizedDisplayName) > 191
            || preg_match('/[\p{Cc}\p{Cf}]/u', $normalizedDisplayName) === 1
        ) {
            throw new InvalidArgumentException('管理者表示名の形式が不正です。');
        }

        if ($passwordHash !== null && !str_starts_with($passwordHash, '$argon2id$')) {
            throw new InvalidArgumentException('パスワードハッシュはArgon2id形式で指定してください。');
        }

        if ($authenticationVersion < 1) {
            throw new InvalidArgumentException('認証版は1以上で指定してください。');
        }

        if ($lockVersion < 0) {
            throw new InvalidArgumentException('ロック版は0以上で指定してください。');
        }

        if ($updatedAt < $createdAt) {
            throw new InvalidArgumentException('更新日時は作成日時以降で指定してください。');
        }

        if (
            $status === AdministratorStatus::Active
            && ($passwordHash === null || $passwordChangedAt === null || $totpEnrolledAt === null)
        ) {
            throw new InvalidArgumentException('有効な管理者にはパスワードとTOTPの登録が必要です。');
        }

        if ($status === AdministratorStatus::Disabled && $disabledAt === null) {
            throw new InvalidArgumentException('無効な管理者には無効化日時が必要です。');
        }

        if ($status === AdministratorStatus::Deleted && ($deletedAt === null || $passwordHash !== null)) {
            throw new InvalidArgumentException('削除済み管理者には削除日時と認証情報の消去が必要です。');
        }

        $this->loginId = $normalizedLoginId;
        $this->displayName = $normalizedDisplayName;
    }
}
