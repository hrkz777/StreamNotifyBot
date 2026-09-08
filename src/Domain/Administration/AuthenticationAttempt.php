<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticationAttempt
{
    public function __construct(
        public string $id,
        public string $loginIdentifierHash,
        public string $sourceIp,
        public DateTimeImmutable $attemptedAt,
        public string $result,
        public ?DateTimeImmutable $retryAfter,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('認証試行IDは小文字標準形式のUUIDv7で指定してください。');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $loginIdentifierHash) !== 1) {
            throw new InvalidArgumentException('ログイン識別子ハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('送信元IPアドレスが不正です。');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $result) !== 1) {
            throw new InvalidArgumentException('認証試行結果の形式が不正です。');
        }

        if ($retryAfter !== null && $retryAfter < $attemptedAt) {
            throw new InvalidArgumentException('再試行可能日時は試行日時以降で指定してください。');
        }

        if ($retryAfter !== null && $result !== 'failure') {
            throw new InvalidArgumentException('再試行可能日時は失敗した認証試行にだけ設定できます。');
        }
    }
}
