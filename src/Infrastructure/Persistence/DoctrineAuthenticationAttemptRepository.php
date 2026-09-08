<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineAuthenticationAttemptRepository implements AuthenticationAttemptRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(AuthenticationAttempt $attempt): void
    {
        $this->connection->insert('authentication_attempts', [
            'id' => Uuid::fromString($attempt->id)->toBinary(),
            'login_identifier_hash' => self::hashToBinary($attempt->loginIdentifierHash),
            'source_ip' => self::normalizeSourceIpForMariaDb($attempt->sourceIp),
            'attempted_at' => self::formatDateTime($attempt->attemptedAt),
            'result' => $attempt->result,
            'retry_after' => self::formatNullableDateTime($attempt->retryAfter),
        ], [
            'id' => ParameterType::BINARY,
            'login_identifier_hash' => ParameterType::BINARY,
            'source_ip' => ParameterType::STRING,
            'attempted_at' => ParameterType::STRING,
            'result' => ParameterType::STRING,
            'retry_after' => ParameterType::STRING,
        ]);
    }

    public function countFailuresSince(string $loginIdentifierHash, string $sourceIp, DateTimeImmutable $since): int
    {
        $value = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM authentication_attempts WHERE login_identifier_hash = ? AND source_ip = ? AND result = 'failure' AND attempted_at >= ?",
            [self::hashToBinary($loginIdentifierHash), self::normalizeSourceIpForMariaDb($sourceIp), self::formatDateTime($since)],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException('認証試行件数の永続データ形式が不正です。');
    }

    public function findRetryAfterSince(
        string $loginIdentifierHash,
        string $sourceIp,
        DateTimeImmutable $since,
        DateTimeImmutable $now,
    ): ?DateTimeImmutable {
        $value = $this->connection->fetchOne(
            "SELECT retry_after FROM authentication_attempts WHERE login_identifier_hash = ? AND source_ip = ? AND result = 'failure' AND attempted_at >= ? AND retry_after > ? ORDER BY retry_after DESC LIMIT 1",
            [
                self::hashToBinary($loginIdentifierHash),
                self::normalizeSourceIpForMariaDb($sourceIp),
                self::formatDateTime($since),
                self::formatDateTime($now),
            ],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        if ($value === false || $value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException('認証試行の再試行可能日時の永続データ形式が不正です。');
        }

        $retryAfter = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));

        return $retryAfter === false
            ? throw new UnexpectedValueException('認証試行の再試行可能日時の永続データ形式が不正です。')
            : $retryAfter;
    }

    private static function hashToBinary(string $hash): string
    {
        $binary = hex2bin($hash);

        return $binary !== false && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1
            ? $binary
            : throw new \InvalidArgumentException('ログイン識別子ハッシュはSHA-256の小文字16進表現で指定してください。');
    }

    private static function normalizeSourceIpForMariaDb(string $sourceIp): string
    {
        $packedIp = inet_pton($sourceIp);
        if ($packedIp === false) {
            throw new \InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        $normalized = inet_ntop($packedIp);
        if ($normalized === false) {
            throw new \InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        return strlen($packedIp) === 4 ? sprintf('::ffff:%s', $normalized) : $normalized;
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullableDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime === null ? null : self::formatDateTime($dateTime);
    }
}
