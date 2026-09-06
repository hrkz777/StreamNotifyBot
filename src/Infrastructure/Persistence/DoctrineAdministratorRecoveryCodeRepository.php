<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorRecoveryCodeRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAdministratorRecoveryCodeRepository implements AdministratorRecoveryCodeRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function replaceForAdministrator(string $administratorId, array $codes): void
    {
        $administratorIdBinary = Uuid::fromString($administratorId)->toBinary();
        foreach ($codes as $code) {
            if ($code->administratorId !== $administratorId) {
                throw new InvalidArgumentException('異なる管理者の回復コードは一括保存できません。');
            }
        }

        $this->connection->transactional(function (Connection $connection) use ($administratorIdBinary, $codes): void {
            $connection->executeStatement(
                'DELETE FROM administrator_recovery_codes WHERE administrator_id = ?',
                [$administratorIdBinary],
                [ParameterType::BINARY],
            );

            foreach ($codes as $code) {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO administrator_recovery_codes (
                            id,
                            administrator_id,
                            code_hash,
                            created_at,
                            used_at
                        ) VALUES (?, ?, ?, ?, ?)
                        SQL,
                    [
                        Uuid::fromString($code->id)->toBinary(),
                        $administratorIdBinary,
                        self::hashToBinary($code->codeHash),
                        self::formatDateTime($code->createdAt),
                        self::formatDateTime($code->usedAt),
                    ],
                    [
                        ParameterType::BINARY,
                        ParameterType::BINARY,
                        ParameterType::BINARY,
                        ParameterType::STRING,
                        ParameterType::STRING,
                    ],
                );
            }
        });
    }

    public function consumeByHash(string $administratorId, string $codeHash, DateTimeImmutable $usedAt): bool
    {
        $formattedUsedAt = self::formatDateTime($usedAt);
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE administrator_recovery_codes
                SET used_at = ?
                WHERE administrator_id = ?
                  AND code_hash = ?
                  AND used_at IS NULL
                  AND created_at <= ?
                SQL,
            [
                $formattedUsedAt,
                Uuid::fromString($administratorId)->toBinary(),
                self::hashToBinary($codeHash),
                $formattedUsedAt,
            ],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );

        return $affectedRows === 1;
    }

    private static function hashToBinary(string $codeHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $codeHash) !== 1) {
            throw new InvalidArgumentException('回復コードハッシュはSHA-256の小文字16進表現で指定してください。');
        }

        $binaryHash = hex2bin($codeHash);

        return $binaryHash !== false
            ? $binaryHash
            : throw new InvalidArgumentException('回復コードハッシュを変換できません。');
    }

    private static function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
