<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpEnrollmentRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAdministratorTotpEnrollmentRepository implements AdministratorTotpEnrollmentRepository
{
    private const int RECOVERY_CODE_COUNT = 10;

    public function __construct(private Connection $connection)
    {
    }

    public function confirm(
        AdministratorTotpCredential $credential,
        array $recoveryCodes,
        DateTimeImmutable $enrolledAt,
    ): bool {
        self::assertRecoveryCodes($credential->administratorId, $recoveryCodes);

        return $this->connection->transactional(function (Connection $connection) use (
            $credential,
            $recoveryCodes,
            $enrolledAt,
        ): bool {
            $administratorIdBinary = Uuid::fromString($credential->administratorId)->toBinary();
            $formattedEnrolledAt = self::formatDateTime($enrolledAt);
            $affectedRows = $connection->executeStatement(
                <<<'SQL'
                    UPDATE administrators
                    SET
                        totp_enrolled_at = ?,
                        updated_at = ?,
                        lock_version = lock_version + 1
                    WHERE id = ?
                      AND status = 'pending'
                      AND totp_enrolled_at IS NULL
                      AND disabled_at IS NULL
                      AND deleted_at IS NULL
                    SQL,
                [$formattedEnrolledAt, $formattedEnrolledAt, $administratorIdBinary],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );

            if ($affectedRows !== 1) {
                return false;
            }

            self::insertCredential($connection, $credential, $administratorIdBinary);
            foreach ($recoveryCodes as $recoveryCode) {
                self::insertRecoveryCode($connection, $recoveryCode, $administratorIdBinary);
            }

            return true;
        });
    }

    /** @param list<AdministratorRecoveryCode> $recoveryCodes */
    private static function assertRecoveryCodes(string $administratorId, array $recoveryCodes): void
    {
        if (count($recoveryCodes) !== self::RECOVERY_CODE_COUNT) {
            throw new InvalidArgumentException('TOTP登録には10件の回復コードが必要です。');
        }

        foreach ($recoveryCodes as $recoveryCode) {
            if ($recoveryCode->administratorId !== $administratorId) {
                throw new InvalidArgumentException('異なる管理者の回復コードはTOTP登録に使用できません。');
            }

            if ($recoveryCode->usedAt !== null) {
                throw new InvalidArgumentException('使用済み回復コードはTOTP登録に使用できません。');
            }
        }
    }

    private static function insertCredential(
        Connection $connection,
        AdministratorTotpCredential $credential,
        string $administratorIdBinary,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrator_totp_credentials (
                    administrator_id,
                    encrypted_value,
                    encryption_nonce,
                    encryption_key_id,
                    encryption_format_version,
                    last_accepted_time_step
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
            [
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ],
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
            <<<'SQL'
                INSERT INTO administrator_recovery_codes (
                    id,
                    administrator_id,
                    code_hash,
                    created_at,
                    used_at
                ) VALUES (?, ?, ?, ?, NULL)
                SQL,
            [
                Uuid::fromString($recoveryCode->id)->toBinary(),
                $administratorIdBinary,
                $binaryHash,
                self::formatDateTime($recoveryCode->createdAt),
            ],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    private static function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
