<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdministratorToken
{
    public function __construct(
        public string $id,
        public ?string $administratorId,
        public AdministratorTokenPurpose $purpose,
        public string $tokenHash,
        public ?string $createdByAdministratorId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $consumedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {
        self::assertUuidV7($id, 'トークンID');
        if ($administratorId !== null) {
            self::assertUuidV7($administratorId, '対象管理者ID');
        }

        if ($createdByAdministratorId !== null) {
            self::assertUuidV7($createdByAdministratorId, '発行管理者ID');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('トークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        if ($purpose !== AdministratorTokenPurpose::InitialSetup && $administratorId === null) {
            throw new InvalidArgumentException('招待・回復トークンには対象管理者が必要です。');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('トークン有効期限は発行日時より後で指定してください。');
        }

        if ($consumedAt !== null && $consumedAt < $createdAt) {
            throw new InvalidArgumentException('トークン消費日時は発行日時以降で指定してください。');
        }

        if ($consumedAt !== null && $consumedAt >= $expiresAt) {
            throw new InvalidArgumentException('トークン消費日時は有効期限より前で指定してください。');
        }

        if ($revokedAt !== null && $revokedAt < $createdAt) {
            throw new InvalidArgumentException('トークン失効日時は発行日時以降で指定してください。');
        }
    }

    public function isAvailableAt(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null
            && $this->revokedAt === null
            && $this->createdAt <= $now
            && $this->expiresAt > $now;
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }
}
