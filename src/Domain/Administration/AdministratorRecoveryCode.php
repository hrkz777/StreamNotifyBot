<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdministratorRecoveryCode
{
    public function __construct(
        public string $id,
        public string $administratorId,
        public string $codeHash,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $usedAt,
    ) {
        self::assertUuidV7($id, '回復コードID');
        self::assertUuidV7($administratorId, '管理者ID');

        if (preg_match('/^[0-9a-f]{64}$/D', $codeHash) !== 1) {
            throw new InvalidArgumentException('回復コードハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        if ($usedAt !== null && $usedAt < $createdAt) {
            throw new InvalidArgumentException('回復コード使用日時は作成日時以降で指定してください。');
        }
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }
}
