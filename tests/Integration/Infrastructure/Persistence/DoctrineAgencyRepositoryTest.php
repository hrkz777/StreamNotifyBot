<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\System\Clock;
use App\Infrastructure\Persistence\DoctrineAgencyRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAgencyRepositoryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itLoadsTheInitialIndependentAgency(): void
    {
        $agency = $this->repository()->findByCode('independent');

        self::assertNotNull($agency);
        self::assertTrue($agency->isIndependent);
        self::assertSame('個人勢', $agency->nameFor(SupportedLanguage::Japanese)->name);
        self::assertSame('Independent', $agency->nameFor(SupportedLanguage::English)->name);
    }

    #[Test]
    public function itStoresAndLoadsAnAgencyAtomically(): void
    {
        $agency = new Agency(
            '01990d4a-0000-7000-8000-000000000020',
            'example_agency',
            SupportedLanguage::Japanese,
            false,
            [
                new AgencyName(SupportedLanguage::Japanese, 'テスト事務所', 'テスト'),
                new AgencyName(SupportedLanguage::English, 'Example Agency'),
            ],
        );

        $repository = $this->repository();
        $repository->add($agency);

        $storedAgency = $repository->findById($agency->id);
        self::assertNotNull($storedAgency);
        self::assertSame($agency->id, $storedAgency->id);
        self::assertSame('テスト', $storedAgency->nameFor(SupportedLanguage::Japanese)->displayName());
        self::assertSame('Example Agency', $storedAgency->nameFor(SupportedLanguage::English)->displayName());
    }

    #[Test]
    public function itFindsAllAgenciesOrderedByCode(): void
    {
        $repository = $this->repository();
        $first = new Agency(
            '01990d4a-0000-7000-8000-000000000111',
            'alpha_agency',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::Japanese, 'アルファ')],
        );
        $second = new Agency(
            '01990d4a-0000-7000-8000-000000000112',
            'zulu_agency',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::Japanese, 'ズールー')],
        );
        $repository->add($second);
        $repository->add($first);

        $agencies = $repository->findAll();

        self::assertSame(['alpha_agency', 'independent', 'zulu_agency'], array_map(static fn (Agency $agency): string => $agency->code, $agencies));
    }

    private function repository(): DoctrineAgencyRepository
    {
        $clock = new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-01 12:34:56.123456', new DateTimeZone('UTC'));
            }
        };

        return new DoctrineAgencyRepository($this->connection, $clock);
    }
}
