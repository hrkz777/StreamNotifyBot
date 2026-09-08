<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorSession;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AdministratorSessionUnavailable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(AdministratorSessionRepository::class)]
final readonly class DoctrineAdministratorSessionRepository implements AdministratorSessionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function start(AdministratorSession $session): void
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_sessions (
                    id,
                    administrator_id,
                    token_hash,
                    authentication_version,
                    created_at,
                    last_activity_at,
                    idle_expires_at,
                    absolute_expires_at,
                    reauthenticated_at,
                    source_ip,
                    user_agent,
                    revoked_at
                )
                SELECT
                    ?,
                    id,
                    ?,
                    authentication_version,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NULL
                FROM administrators
                WHERE id = ?
                  AND status = 'active'
                  AND authentication_version = ?
                SQL,
            [
                Uuid::fromString($session->id)->toBinary(),
                self::hashToBinary($session->tokenHash),
                self::formatDateTime($session->createdAt),
                self::formatDateTime($session->lastActivityAt),
                self::formatDateTime($session->idleExpiresAt),
                self::formatDateTime($session->absoluteExpiresAt),
                self::formatDateTime($session->reauthenticatedAt),
                self::normalizeSourceIpForMariaDb($session->sourceIp),
                $session->userAgent,
                Uuid::fromString($session->administratorId)->toBinary(),
                $session->authenticationVersion,
            ],
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::INTEGER,
            ],
        );

        if ($affectedRows !== 1) {
            throw new AdministratorSessionUnavailable();
        }
    }

    public function touch(
        string $tokenHash,
        string $administratorId,
        int $authenticationVersion,
        DateTimeImmutable $now,
        DateTimeImmutable $idleExpiresAt,
    ): bool {
        if ($idleExpiresAt <= $now) {
            throw new InvalidArgumentException('更新後の無操作期限は現在時刻より後で指定してください。');
        }

        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE administrator_sessions session
                INNER JOIN administrators administrator
                    ON administrator.id = session.administrator_id
                SET session.last_activity_at = ?,
                    session.idle_expires_at = LEAST(session.absolute_expires_at, ?)
                WHERE session.token_hash = ?
                  AND session.administrator_id = ?
                  AND session.authentication_version = ?
                  AND session.revoked_at IS NULL
                  AND session.created_at <= ?
                  AND session.last_activity_at <= ?
                  AND session.idle_expires_at > ?
                  AND session.absolute_expires_at > ?
                  AND administrator.status = 'active'
                  AND administrator.authentication_version = ?
                SQL,
            [
                self::formatDateTime($now),
                self::formatDateTime($idleExpiresAt),
                self::hashToBinary($tokenHash),
                Uuid::fromString($administratorId)->toBinary(),
                $authenticationVersion,
                self::formatDateTime($now),
                self::formatDateTime($now),
                self::formatDateTime($now),
                self::formatDateTime($now),
                $authenticationVersion,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );

        return $affectedRows === 1;
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE administrator_sessions
                SET revoked_at = ?
                WHERE token_hash = ?
                  AND revoked_at IS NULL
                  AND created_at <= ?
                SQL,
            [
                self::formatDateTime($revokedAt),
                self::hashToBinary($tokenHash),
                self::formatDateTime($revokedAt),
            ],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    public function markReauthenticated(
        string $tokenHash,
        string $administratorId,
        int $authenticationVersion,
        DateTimeImmutable $reauthenticatedAt,
    ): bool {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE administrator_sessions session
                INNER JOIN administrators administrator
                    ON administrator.id = session.administrator_id
                SET session.reauthenticated_at = ?
                WHERE session.token_hash = ?
                  AND session.administrator_id = ?
                  AND session.authentication_version = ?
                  AND session.revoked_at IS NULL
                  AND session.created_at <= ?
                  AND session.last_activity_at <= ?
                  AND session.idle_expires_at > ?
                  AND session.absolute_expires_at > ?
                  AND administrator.status = 'active'
                  AND administrator.authentication_version = ?
                SQL,
            [
                self::formatDateTime($reauthenticatedAt),
                self::hashToBinary($tokenHash),
                Uuid::fromString($administratorId)->toBinary(),
                $authenticationVersion,
                self::formatDateTime($reauthenticatedAt),
                self::formatDateTime($reauthenticatedAt),
                self::formatDateTime($reauthenticatedAt),
                self::formatDateTime($reauthenticatedAt),
                $authenticationVersion,
            ],
            [
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );

        return $affectedRows === 1;
    }

    private static function hashToBinary(string $tokenHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('セッショントークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        $binaryHash = hex2bin($tokenHash);

        return $binaryHash !== false
            ? $binaryHash
            : throw new InvalidArgumentException('セッショントークンハッシュを変換できません。');
    }

    private static function normalizeSourceIpForMariaDb(string $sourceIp): string
    {
        $packedIp = inet_pton($sourceIp);

        if ($packedIp === false) {
            throw new InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        $normalizedIp = inet_ntop($packedIp);

        if ($normalizedIp === false) {
            throw new InvalidArgumentException('送信元IPアドレスを正規化できません。');
        }

        return strlen($packedIp) === 4
            ? sprintf('::ffff:%s', $normalizedIp)
            : $normalizedIp;
    }

    private static function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
