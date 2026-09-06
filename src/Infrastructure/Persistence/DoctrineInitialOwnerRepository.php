<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorAlreadyExists;
use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyNotFound;
use App\Domain\Administration\InitialOwnerRepository;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineInitialOwnerRepository implements InitialOwnerRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(
        Administrator $owner,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $completedAt,
    ): void {
        self::assertAggregate($owner, $credential, $recoveryCodes, $completedAt);

        $this->connection->transactional(function (Connection $connection) use (
            $owner,
            $credential,
            $recoveryCodes,
            $completedAt,
        ): void {
            self::lockAndAssertAvailable($connection, $completedAt);
            $administratorIdBinary = Uuid::fromString($owner->id)->toBinary();
            self::insertOwner($connection, $owner, $administratorIdBinary);
            self::insertCredential($connection, $credential, $administratorIdBinary);
            foreach ($recoveryCodes as $recoveryCode) {
                self::insertRecoveryCode($connection, $recoveryCode, $administratorIdBinary);
            }

            self::activateOwner($connection, $owner->id, $completedAt);
            self::completeInitialSetup($connection, $completedAt);
        });
    }

    private static function lockAndAssertAvailable(Connection $connection, DateTimeImmutable $completedAt): void
    {
        $policy = $connection->fetchAssociative(
            'SELECT initial_setup_completed_at, updated_at FROM authentication_policies WHERE id = ? FOR UPDATE',
            [Uuid::fromString(AuthenticationPolicy::ID)->toBinary()],
            [ParameterType::BINARY],
        );
        if ($policy === false) {
            throw new AuthenticationPolicyNotFound();
        }

        if ($policy['initial_setup_completed_at'] !== null) {
            throw new InitialSetupAlreadyCompleted();
        }

        if (!is_string($policy['updated_at'])) {
            throw new RuntimeException('認証方針の更新日時が不正です。');
        }

        if ($completedAt < self::parseDateTime($policy['updated_at'])) {
            throw new InvalidArgumentException('初期設定完了日時が認証方針の更新日時より前です。');
        }

        if ($connection->fetchOne('SELECT id FROM administrators ORDER BY id LIMIT 1 FOR UPDATE') !== false) {
            throw new AdministratorAlreadyExists();
        }
    }

    private static function insertOwner(Connection $connection, Administrator $owner, string $administratorIdBinary): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrators (
                    id, login_id, display_name, role, status, password_hash,
                    authentication_version, password_changed_at, totp_enrolled_at,
                    last_login_at, disabled_at, deleted_at, created_at, updated_at, lock_version
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?)
                SQL,
            [
                $administratorIdBinary,
                $owner->loginId,
                $owner->displayName,
                $owner->role->value,
                $owner->status->value,
                $owner->passwordHash,
                $owner->authenticationVersion,
                self::formatDateTime($owner->passwordChangedAt),
                self::formatDateTime($owner->createdAt),
                self::formatDateTime($owner->updatedAt),
                $owner->lockVersion,
            ],
            [
                ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::INTEGER,
            ],
        );
    }

    private static function insertCredential(
        Connection $connection,
        AdministratorTotpCredential $credential,
        string $administratorIdBinary,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_totp_credentials (
                    administrator_id, encrypted_value, encryption_nonce, encryption_key_id,
                    encryption_format_version, last_accepted_time_step
                ) VALUES (?, ?, ?, ?, ?, ?)
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

    private static function insertRecoveryCode(
        Connection $connection,
        AdministratorRecoveryCode $recoveryCode,
        string $administratorIdBinary,
    ): void {
        $binaryHash = hex2bin($recoveryCode->codeHash);
        if ($binaryHash === false) {
            throw new InvalidArgumentException('回復コードハッシュを変換できません。');
        }

        $connection->executeStatement(
            'INSERT INTO administrator_recovery_codes (id, administrator_id, code_hash, created_at, used_at) VALUES (?, ?, ?, ?, NULL)',
            [Uuid::fromString($recoveryCode->id)->toBinary(), $administratorIdBinary, $binaryHash,
                self::formatDateTime($recoveryCode->createdAt)],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    private static function activateOwner(Connection $connection, string $administratorId, DateTimeImmutable $completedAt): void
    {
        $affectedRows = $connection->executeStatement(
            <<<'SQL'
                UPDATE administrators
                SET status = 'active', totp_enrolled_at = ?, updated_at = ?, lock_version = lock_version + 1
                WHERE id = ? AND role = 'owner' AND status = 'pending'
                  AND password_hash IS NOT NULL AND password_changed_at IS NOT NULL
                  AND totp_enrolled_at IS NULL AND disabled_at IS NULL AND deleted_at IS NULL
                SQL,
            [self::formatDateTime($completedAt), self::formatDateTime($completedAt), Uuid::fromString($administratorId)->toBinary()],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );
        if ($affectedRows !== 1) {
            throw new RuntimeException('初期ownerを有効化できませんでした。');
        }
    }

    private static function completeInitialSetup(Connection $connection, DateTimeImmutable $completedAt): void
    {
        $affectedRows = $connection->executeStatement(
            <<<'SQL'
                UPDATE authentication_policies
                SET initial_setup_completed_at = ?, updated_at = ?, lock_version = lock_version + 1
                WHERE id = ? AND initial_setup_completed_at IS NULL
                SQL,
            [self::formatDateTime($completedAt), self::formatDateTime($completedAt),
                Uuid::fromString(AuthenticationPolicy::ID)->toBinary()],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );
        if ($affectedRows !== 1) {
            throw new RuntimeException('初期設定完了状態を更新できませんでした。');
        }
    }

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    private static function assertAggregate(
        Administrator $owner,
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $completedAt,
    ): void {
        if ($owner->role !== AdministratorRole::Owner || $owner->status !== AdministratorStatus::Pending) {
            throw new InvalidArgumentException('初期管理者はPending状態のownerである必要があります。');
        }

        if (
            $owner->passwordHash === null
            || $owner->passwordChangedAt === null
            || $owner->totpEnrolledAt !== null
            || $owner->lastLoginAt !== null
            || $owner->disabledAt !== null
            || $owner->deletedAt !== null
            || $owner->authenticationVersion !== 1
            || $owner->lockVersion !== 0
        ) {
            throw new InvalidArgumentException('初期ownerの認証情報状態が不正です。');
        }

        if (password_get_info($owner->passwordHash)['algoName'] !== 'argon2id') {
            throw new InvalidArgumentException('初期ownerのパスワードハッシュが不正です。');
        }

        if ($credential->administratorId !== $owner->id || $credential->lastAcceptedTimeStep === null) {
            throw new InvalidArgumentException('初期ownerのTOTP資格情報が不正です。');
        }

        if (count($recoveryCodes) !== 10) {
            throw new InvalidArgumentException('初期ownerには10件の回復コードが必要です。');
        }

        $ids = [];
        $hashes = [];
        foreach ($recoveryCodes as $recoveryCode) {
            if (
                $recoveryCode->administratorId !== $owner->id
                || $recoveryCode->usedAt !== null
                || $recoveryCode->createdAt < $owner->createdAt
                || $completedAt < $recoveryCode->createdAt
            ) {
                throw new InvalidArgumentException('初期ownerの回復コードが不正です。');
            }

            if (isset($ids[$recoveryCode->id]) || isset($hashes[$recoveryCode->codeHash])) {
                throw new InvalidArgumentException('初期ownerの回復コードに重複があります。');
            }

            $ids[$recoveryCode->id] = true;
            $hashes[$recoveryCode->codeHash] = true;
        }

        if (
            $completedAt < $owner->createdAt
            || $completedAt < $owner->updatedAt
            || $completedAt < $owner->passwordChangedAt
        ) {
            throw new InvalidArgumentException('初期設定完了日時が管理者作成日時より前です。');
        }
    }

    private static function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parseDateTime(string $dateTime): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $dateTime, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s.u') !== $dateTime) {
            throw new RuntimeException('認証方針の更新日時が不正です。');
        }

        return $parsed;
    }
}
