<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\System\Clock;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

final readonly class DoctrineAgencyRepository implements AgencyRepository
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    public function add(Agency $agency): void
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
        $binaryId = Uuid::fromString($agency->id)->toBinary();

        $this->connection->transactional(function (Connection $connection) use ($agency, $binaryId, $now): void {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO agencies (
                        id,
                        code,
                        default_language_code,
                        is_independent,
                        created_at,
                        updated_at,
                        lock_version
                    ) VALUES (?, ?, ?, ?, ?, ?, 0)
                    SQL,
                [
                    $binaryId,
                    $agency->code,
                    $agency->defaultLanguage->value,
                    $agency->isIndependent ? 1 : 0,
                    $now,
                    $now,
                ],
                [
                    ParameterType::BINARY,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            );

            foreach ($agency->names() as $name) {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO agency_names (
                            agency_id,
                            language_code,
                            name,
                            short_name,
                            created_at,
                            updated_at,
                            lock_version
                        ) VALUES (?, ?, ?, ?, ?, ?, 0)
                        SQL,
                    [
                        $binaryId,
                        $name->language->value,
                        $name->name,
                        $name->shortName,
                        $now,
                        $now,
                    ],
                    [
                        ParameterType::BINARY,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                    ],
                );
            }
        });
    }

    public function findById(string $id): ?Agency
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, code, default_language_code, is_independent FROM agencies WHERE id = ?',
            [Uuid::fromString($id)->toBinary()],
            [ParameterType::BINARY],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?Agency
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, code, default_language_code, is_independent FROM agencies WHERE code = ?',
            [$code],
            [ParameterType::STRING],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Agency
    {
        if (
            !is_string($row['id'] ?? null)
            || !is_string($row['code'] ?? null)
            || !is_string($row['default_language_code'] ?? null)
            || (!is_int($row['is_independent'] ?? null) && !is_string($row['is_independent'] ?? null))
        ) {
            throw new UnexpectedValueException('所属区分の永続データ形式が不正です。');
        }

        $nameRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT language_code, name, short_name
                FROM agency_names
                WHERE agency_id = ?
                ORDER BY language_code
                SQL,
            [$row['id']],
            [ParameterType::BINARY],
        );

        return new Agency(
            Uuid::fromBinary($row['id'])->toRfc4122(),
            $row['code'],
            SupportedLanguage::from($row['default_language_code']),
            $row['is_independent'] === 1 || $row['is_independent'] === '1',
            array_map(
                self::hydrateName(...),
                $nameRows,
            ),
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrateName(array $row): AgencyName
    {
        if (
            !is_string($row['language_code'] ?? null)
            || !is_string($row['name'] ?? null)
            || !array_key_exists('short_name', $row)
            || (!is_string($row['short_name']) && $row['short_name'] !== null)
        ) {
            throw new UnexpectedValueException('所属区分名称の永続データ形式が不正です。');
        }

        return new AgencyName(
            SupportedLanguage::from($row['language_code']),
            $row['name'],
            $row['short_name'],
        );
    }
}
