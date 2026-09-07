<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdministratorSession
{
    private const int MAX_AUTHENTICATION_VERSION = 4_294_967_295;

    public function __construct(
        public string $id,
        public string $administratorId,
        public string $tokenHash,
        public int $authenticationVersion,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActivityAt,
        public DateTimeImmutable $idleExpiresAt,
        public DateTimeImmutable $absoluteExpiresAt,
        public ?DateTimeImmutable $reauthenticatedAt,
        public string $sourceIp,
        public ?string $userAgent,
        public ?DateTimeImmutable $revokedAt,
    ) {
        self::assertUuidV7($id, '管理者セッションID');
        self::assertUuidV7($administratorId, '管理者ID');

        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('セッショントークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        if ($authenticationVersion < 1 || $authenticationVersion > self::MAX_AUTHENTICATION_VERSION) {
            throw new InvalidArgumentException('管理者セッションの認証版が不正です。');
        }

        if ($lastActivityAt < $createdAt) {
            throw new InvalidArgumentException('最終操作日時はセッション作成日時以降で指定してください。');
        }

        if ($idleExpiresAt <= $lastActivityAt) {
            throw new InvalidArgumentException('無操作期限は最終操作日時より後で指定してください。');
        }

        if ($absoluteExpiresAt < $idleExpiresAt) {
            throw new InvalidArgumentException('絶対期限は無操作期限以降で指定してください。');
        }

        if ($reauthenticatedAt !== null && ($reauthenticatedAt < $createdAt || $reauthenticatedAt > $lastActivityAt)) {
            throw new InvalidArgumentException('再認証日時はセッション作成日時から最終操作日時までの範囲で指定してください。');
        }

        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('送信元IPアドレスが不正です。');
        }

        if ($userAgent !== null && mb_strlen($userAgent, 'UTF-8') > 512) {
            throw new InvalidArgumentException('User-Agentは512文字以下で指定してください。');
        }

        if ($revokedAt !== null && $revokedAt < $createdAt) {
            throw new InvalidArgumentException('セッション失効日時は作成日時以降で指定してください。');
        }
    }

    private static function assertUuidV7(string $id, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('%sは小文字標準形式のUUIDv7で指定してください。', $label));
        }
    }
}
