<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorInvitationAcceptanceRepository;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorTotpCredential;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAdministratorInvitationAcceptanceRepository implements AdministratorInvitationAcceptanceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findTargetAdministratorId(string $tokenHash, DateTimeImmutable $now): ?string
    {
        $targetId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT administrator.id
                FROM administrator_tokens AS token
                INNER JOIN administrators AS administrator ON administrator.id = token.administrator_id
                WHERE token.token_hash = ?
                  AND token.purpose = 'invitation'
                  AND token.consumed_at IS NULL
                  AND token.revoked_at IS NULL
                  AND token.created_at <= ?
                  AND token.expires_at > ?
                  AND token.authentication_version = administrator.authentication_version
                  AND administrator.status = 'pending'
                  AND administrator.password_hash IS NULL
                  AND administrator.password_changed_at IS NULL
                  AND administrator.totp_enrolled_at IS NULL
                  AND administrator.disabled_at IS NULL
                  AND administrator.deleted_at IS NULL
                SQL,
            [self::tokenHashToBinary($tokenHash), self::formatDateTime($now), self::formatDateTime($now)],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        if ($targetId === false) {
            return null;
        }

        if (!is_string($targetId)) {
            throw new RuntimeException('招待対象管理者IDの永続データ形式が不正です。');
        }

        return Uuid::fromBinary($targetId)->toRfc4122();
    }

    public function accept(
        string $tokenHash,
        string $administratorId,
        string $passwordHash,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $acceptedAt,
    ): bool {
        self::assertAcceptance($administratorId, $passwordHash, $credential, $recoveryCodes, $acceptedAt);
        $binaryTokenHash = self::tokenHashToBinary($tokenHash);
        $binaryAdministratorId = Uuid::fromString($administratorId)->toBinary();

        return $this->connection->transactional(function (Connection $connection) use (
            $binaryTokenHash,
            $binaryAdministratorId,
            $passwordHash,
            $credential,
            $recoveryCodes,
            $acceptedAt,
        ): bool {
            $token = $connection->fetchAssociative(
                <<<'SQL'
                    SELECT token.id AS token_id, token.administrator_id
                    FROM administrator_tokens AS token
                    INNER JOIN administrators AS administrator ON administrator.id = token.administrator_id
                    WHERE token.token_hash = ?
                      AND token.purpose = 'invitation'
                      AND token.consumed_at IS NULL
                      AND token.revoked_at IS NULL
                      AND token.created_at <= ?
                      AND token.expires_at > ?
                      AND token.authentication_version = administrator.authentication_version
                      AND administrator.id = ?
                      AND administrator.status = 'pending'
                      AND administrator.password_hash IS NULL
                      AND administrator.password_changed_at IS NULL
                      AND administrator.totp_enrolled_at IS NULL
                      AND administrator.disabled_at IS NULL
                      AND administrator.deleted_at IS NULL
                    FOR UPDATE
                    SQL,
                [
                    $binaryTokenHash,
                    self::formatDateTime($acceptedAt),
                    self::formatDateTime($acceptedAt),
                    $binaryAdministratorId,
                ],
                [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );
            if ($token === false) {
                return false;
            }
            if (!is_string($token['token_id']) || !is_string($token['administrator_id'])) {
                throw new RuntimeException('招待トークンの永続データ形式が不正です。');
            }

            $time = self::formatDateTime($acceptedAt);
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO administrator_totp_credentials (
                        administrator_id, encrypted_value, encryption_nonce, encryption_key_id,
                        encryption_format_version, last_accepted_time_step
                    ) VALUES (?, ?, ?, ?, ?, ?)
                    SQL,
                [
                    $binaryAdministratorId,
                    $credential->encryptedSecret->encryptedValue,
                    $credential->encryptedSecret->nonce,
                    $credential->encryptedSecret->keyId,
                    $credential->encryptedSecret->formatVersion,
                    $credential->lastAcceptedTimeStep,
                ],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                    ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            foreach ($recoveryCodes as $recoveryCode) {
                self::insertRecoveryCode($connection, $recoveryCode, $binaryAdministratorId);
            }

            $activated = $connection->executeStatement(
                <<<'SQL'
                    UPDATE administrators
                    SET password_hash = ?, password_changed_at = ?, totp_enrolled_at = ?,
                        status = 'active', updated_at = ?, lock_version = lock_version + 1
                    WHERE id = ? AND status = 'pending' AND password_hash IS NULL
                      AND password_changed_at IS NULL AND totp_enrolled_at IS NULL
                      AND disabled_at IS NULL AND deleted_at IS NULL
                    SQL,
                [$passwordHash, $time, $time, $time, $binaryAdministratorId],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );
            if ($activated !== 1) {
                throw new RuntimeException('招待対象管理者を有効化できませんでした。');
            }

            $consumed = $connection->executeStatement(
                <<<'SQL'
                    UPDATE administrator_tokens
                    SET consumed_at = ?
                    WHERE id = ? AND administrator_id = ? AND consumed_at IS NULL AND revoked_at IS NULL
                      AND created_at <= ? AND expires_at > ?
                    SQL,
                [$time, $token['token_id'], $binaryAdministratorId, $time, $time],
                [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
            );
            if ($consumed !== 1) {
                throw new RuntimeException('招待トークンを消費できませんでした。');
            }

            return true;
        });
    }

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    private static function assertAcceptance(
        string $administratorId,
        string $passwordHash,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $acceptedAt,
    ): void {
        if (password_get_info($passwordHash)['algoName'] !== 'argon2id') {
            throw new InvalidArgumentException('招待対象管理者のパスワードハッシュが不正です。');
        }

        if ($credential->administratorId !== $administratorId || $credential->lastAcceptedTimeStep === null) {
            throw new InvalidArgumentException('招待対象管理者のTOTP資格情報が不正です。');
        }

        if (count($recoveryCodes) !== 10) {
            throw new InvalidArgumentException('招待対象管理者には10件の回復コードが必要です。');
        }

        $ids = [];
        $hashes = [];
        foreach ($recoveryCodes as $recoveryCode) {
            if (
                $recoveryCode->administratorId !== $administratorId
                || $recoveryCode->usedAt !== null
                || $recoveryCode->createdAt > $acceptedAt
            ) {
                throw new InvalidArgumentException('招待対象管理者の回復コードが不正です。');
            }

            if (isset($ids[$recoveryCode->id]) || isset($hashes[$recoveryCode->codeHash])) {
                throw new InvalidArgumentException('招待対象管理者の回復コードに重複があります。');
            }

            $ids[$recoveryCode->id] = true;
            $hashes[$recoveryCode->codeHash] = true;
        }
    }

    private static function insertRecoveryCode(
        Connection $connection,
        AdministratorRecoveryCode $recoveryCode,
        string $binaryAdministratorId,
    ): void {
        $codeHash = hex2bin($recoveryCode->codeHash);
        if ($codeHash === false) {
            throw new InvalidArgumentException('回復コードハッシュを変換できません。');
        }

        $connection->executeStatement(
            'INSERT INTO administrator_recovery_codes (id, administrator_id, code_hash, created_at, used_at) VALUES (?, ?, ?, ?, NULL)',
            [
                Uuid::fromString($recoveryCode->id)->toBinary(),
                $binaryAdministratorId,
                $codeHash,
                self::formatDateTime($recoveryCode->createdAt),
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    private static function tokenHashToBinary(string $tokenHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('トークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        $binaryTokenHash = hex2bin($tokenHash);
        if ($binaryTokenHash === false) {
            throw new InvalidArgumentException('トークンハッシュを変換できません。');
        }

        return $binaryTokenHash;
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
