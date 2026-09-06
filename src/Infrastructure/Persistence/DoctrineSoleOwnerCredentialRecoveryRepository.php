<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyNotFound;
use App\Domain\Administration\ConcurrentSoleOwnerRecovery;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\Administration\SoleOwnerRecoveryUnavailable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSoleOwnerCredentialRecoveryRepository implements SoleOwnerCredentialRecoveryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findTarget(): SoleOwnerRecoveryTarget
    {
        $completedAt = $this->connection->fetchOne(
            'SELECT initial_setup_completed_at FROM authentication_policies WHERE id = ?',
            [Uuid::fromString(AuthenticationPolicy::ID)->toBinary()],
            [ParameterType::BINARY],
        );
        if ($completedAt === false) {
            throw new AuthenticationPolicyNotFound();
        }

        if ($completedAt === null) {
            throw new SoleOwnerRecoveryUnavailable();
        }

        return self::targetFromRows(self::fetchNonDeletedOwners($this->connection, false));
    }

    public function recover(
        SoleOwnerRecoveryTarget $expectedTarget,
        #[SensitiveParameter]
        string $passwordHash,
        #[SensitiveParameter]
        AdministratorTotpCredential $credential,
        #[SensitiveParameter]
        array $recoveryCodes,
        DateTimeImmutable $recoveredAt,
    ): void {
        self::assertAggregate($expectedTarget, $passwordHash, $credential, $recoveryCodes, $recoveredAt);

        $this->connection->transactional(function (Connection $connection) use (
            $expectedTarget,
            $passwordHash,
            $credential,
            $recoveryCodes,
            $recoveredAt,
        ): void {
            self::lockPolicy($connection, $recoveredAt);
            $owner = self::lockAndAssertTarget($connection, $expectedTarget, $recoveredAt);
            if (
                self::readUnsignedInteger($owner, 'authentication_version') >= 4_294_967_295
                || self::readUnsignedInteger($owner, 'lock_version') >= PHP_INT_MAX
            ) {
                throw new RuntimeException('ownerの版数を更新できません。');
            }

            $administratorIdBinary = Uuid::fromString($expectedTarget->administratorId)->toBinary();
            self::lockAndAssertRevocableRecords($connection, $administratorIdBinary, $recoveredAt);
            self::replaceCredential($connection, $credential, $administratorIdBinary);
            self::replaceRecoveryCodes($connection, $recoveryCodes, $administratorIdBinary);
            self::updateOwner($connection, $expectedTarget, $passwordHash, $recoveredAt);
            self::revokeSessionsAndTokens($connection, $administratorIdBinary, $recoveredAt);
        });
    }

    private static function lockPolicy(Connection $connection, DateTimeImmutable $recoveredAt): void
    {
        $policy = $connection->fetchAssociative(
            'SELECT initial_setup_completed_at, updated_at FROM authentication_policies WHERE id = ? FOR UPDATE',
            [Uuid::fromString(AuthenticationPolicy::ID)->toBinary()],
            [ParameterType::BINARY],
        );
        if ($policy === false) {
            throw new AuthenticationPolicyNotFound();
        }

        if ($policy['initial_setup_completed_at'] === null || !is_string($policy['updated_at'])) {
            throw new SoleOwnerRecoveryUnavailable();
        }

        if ($recoveredAt < self::parseDateTime($policy['updated_at'])) {
            throw new InvalidArgumentException('資格情報回復日時が認証方針の更新日時より前です。');
        }
    }

    /** @return array<string, mixed> */
    private static function lockAndAssertTarget(
        Connection $connection,
        SoleOwnerRecoveryTarget $expectedTarget,
        DateTimeImmutable $recoveredAt,
    ): array {
        $rows = self::fetchNonDeletedOwners($connection, true);
        $target = self::targetFromRows($rows);
        $owner = $rows[0];
        if (
            $target->administratorId !== $expectedTarget->administratorId
            || $target->loginId !== $expectedTarget->loginId
            || $target->displayName !== $expectedTarget->displayName
            || $target->lockVersion !== $expectedTarget->lockVersion
        ) {
            throw new ConcurrentSoleOwnerRecovery();
        }

        if (!is_string($owner['updated_at'] ?? null) || $recoveredAt < self::parseDateTime($owner['updated_at'])) {
            throw new InvalidArgumentException('資格情報回復日時がownerの更新日時より前です。');
        }

        return $owner;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchNonDeletedOwners(Connection $connection, bool $forUpdate): array
    {
        $sql = <<<'SQL'
            SELECT id, login_id, display_name, status, disabled_at, authentication_version, updated_at, lock_version
            FROM administrators
            WHERE role = 'owner' AND (status <> 'deleted' OR deleted_at IS NULL)
            ORDER BY id
            SQL;
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return $connection->fetchAllAssociative($sql);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function targetFromRows(array $rows): SoleOwnerRecoveryTarget
    {
        if (count($rows) !== 1) {
            throw new SoleOwnerRecoveryUnavailable();
        }

        $row = $rows[0];
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['login_id'] ?? null)
            || !is_string($row['display_name'] ?? null)
            || ($row['status'] ?? null) !== 'active'
            || ($row['disabled_at'] ?? null) !== null
        ) {
            throw new SoleOwnerRecoveryUnavailable();
        }

        return new SoleOwnerRecoveryTarget(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            $row['login_id'],
            $row['display_name'],
            self::readUnsignedInteger($row, 'lock_version'),
        );
    }

    private static function replaceCredential(
        Connection $connection,
        #[SensitiveParameter]
        AdministratorTotpCredential $credential,
        string $administratorIdBinary,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_totp_credentials (
                    administrator_id, encrypted_value, encryption_nonce, encryption_key_id,
                    encryption_format_version, last_accepted_time_step
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    encrypted_value = VALUES(encrypted_value),
                    encryption_nonce = VALUES(encryption_nonce),
                    encryption_key_id = VALUES(encryption_key_id),
                    encryption_format_version = VALUES(encryption_format_version),
                    last_accepted_time_step = VALUES(last_accepted_time_step)
                SQL,
            [
                $administratorIdBinary,
                $credential->encryptedSecret->encryptedValue,
                $credential->encryptedSecret->nonce,
                $credential->encryptedSecret->keyId,
                $credential->encryptedSecret->formatVersion,
                $credential->lastAcceptedTimeStep,
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    private static function replaceRecoveryCodes(
        Connection $connection,
        #[SensitiveParameter]
        array $recoveryCodes,
        string $administratorIdBinary,
    ): void {
        $connection->executeStatement(
            'DELETE FROM administrator_recovery_codes WHERE administrator_id = ?',
            [$administratorIdBinary],
            [ParameterType::BINARY],
        );

        foreach ($recoveryCodes as $recoveryCode) {
            $binaryHash = hex2bin($recoveryCode->codeHash);
            if ($binaryHash === false) {
                throw new InvalidArgumentException('回復コードハッシュを変換できません。');
            }

            $connection->executeStatement(
                'INSERT INTO administrator_recovery_codes (id, administrator_id, code_hash, created_at, used_at) VALUES (?, ?, ?, ?, NULL)',
                [Uuid::fromString($recoveryCode->id)->toBinary(), $administratorIdBinary,
                    $binaryHash, self::formatDateTime($recoveryCode->createdAt)],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
            );
        }
    }

    private static function updateOwner(
        Connection $connection,
        SoleOwnerRecoveryTarget $expectedTarget,
        #[SensitiveParameter]
        string $passwordHash,
        DateTimeImmutable $recoveredAt,
    ): void {
        $affectedRows = $connection->executeStatement(
            <<<'SQL'
                UPDATE administrators
                SET password_hash = ?, password_changed_at = ?, totp_enrolled_at = ?,
                    authentication_version = authentication_version + 1,
                    updated_at = ?, lock_version = lock_version + 1
                WHERE id = ? AND role = 'owner' AND status = 'active'
                  AND disabled_at IS NULL AND deleted_at IS NULL AND lock_version = ?
                SQL,
            [$passwordHash, self::formatDateTime($recoveredAt), self::formatDateTime($recoveredAt),
                self::formatDateTime($recoveredAt), Uuid::fromString($expectedTarget->administratorId)->toBinary(),
                $expectedTarget->lockVersion],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER],
        );
        if ($affectedRows !== 1) {
            throw new ConcurrentSoleOwnerRecovery();
        }
    }

    private static function revokeSessionsAndTokens(
        Connection $connection,
        string $administratorIdBinary,
        DateTimeImmutable $recoveredAt,
    ): void {
        $parameters = [self::formatDateTime($recoveredAt), $administratorIdBinary];
        $types = [ParameterType::STRING, ParameterType::BINARY];
        $connection->executeStatement(
            'UPDATE administrator_sessions SET revoked_at = ? WHERE administrator_id = ? AND revoked_at IS NULL',
            $parameters,
            $types,
        );
        $connection->executeStatement(
            'UPDATE administrator_tokens SET revoked_at = ? WHERE administrator_id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
            $parameters,
            $types,
        );
    }

    private static function lockAndAssertRevocableRecords(
        Connection $connection,
        string $administratorIdBinary,
        DateTimeImmutable $recoveredAt,
    ): void {
        foreach (['administrator_sessions', 'administrator_tokens'] as $table) {
            $latestCreatedAt = $connection->fetchOne(
                sprintf(
                    'SELECT created_at FROM %s WHERE administrator_id = ? ORDER BY created_at DESC LIMIT 1 FOR UPDATE',
                    $table,
                ),
                [$administratorIdBinary],
                [ParameterType::BINARY],
            );
            if ($latestCreatedAt === false) {
                continue;
            }

            if (!is_string($latestCreatedAt) || $recoveredAt < self::parseDateTime($latestCreatedAt)) {
                throw new InvalidArgumentException('資格情報回復日時より後に作成された認証状態があります。');
            }
        }
    }

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    private static function assertAggregate(
        SoleOwnerRecoveryTarget $expectedTarget,
        #[SensitiveParameter]
        string $passwordHash,
        #[SensitiveParameter]
        AdministratorTotpCredential $credential,
        #[SensitiveParameter]
        array $recoveryCodes,
        DateTimeImmutable $recoveredAt,
    ): void {
        if (password_get_info($passwordHash)['algoName'] !== 'argon2id') {
            throw new InvalidArgumentException('回復後のパスワードハッシュが不正です。');
        }

        if ($credential->administratorId !== $expectedTarget->administratorId || $credential->lastAcceptedTimeStep === null) {
            throw new InvalidArgumentException('回復後のTOTP資格情報が不正です。');
        }

        if (count($recoveryCodes) !== 10) {
            throw new InvalidArgumentException('資格情報回復には10件の回復コードが必要です。');
        }

        $ids = [];
        $hashes = [];
        foreach ($recoveryCodes as $recoveryCode) {
            if (
                $recoveryCode->administratorId !== $expectedTarget->administratorId
                || $recoveryCode->usedAt !== null
                || $recoveryCode->createdAt != $recoveredAt
            ) {
                throw new InvalidArgumentException('回復後の回復コードが不正です。');
            }

            if (isset($ids[$recoveryCode->id]) || isset($hashes[$recoveryCode->codeHash])) {
                throw new InvalidArgumentException('回復後の回復コードに重複があります。');
            }

            $ids[$recoveryCode->id] = true;
            $hashes[$recoveryCode->codeHash] = true;
        }
    }

    /** @param array<string, mixed> $row */
    private static function readUnsignedInteger(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new RuntimeException(sprintf('%sの永続データ形式が不正です。', $key));
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseDateTime(string $dateTime): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $dateTime, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s.u') !== $dateTime) {
            throw new RuntimeException('日時の永続データ形式が不正です。');
        }

        return $parsed;
    }
}
