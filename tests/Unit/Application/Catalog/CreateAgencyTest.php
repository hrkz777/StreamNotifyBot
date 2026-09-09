<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\AgencyAlreadyExists;
use App\Application\Catalog\CreateAgency;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\System\IdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateAgencyTest extends TestCase
{
    #[Test]
    public function itCreatesAnAgencyWithTheGeneratedId(): void
    {
        $repository = $this->createMock(AgencyRepository::class);
        $repository->expects(self::once())->method('findByCode')->with('independent')->willReturn(null);
        $repository->expects(self::once())
            ->method('add')
            ->with(self::callback(static fn (Agency $agency): bool => $agency->id === '0199d534-0000-7000-8000-000000000101'
                && $agency->code === 'independent'
                && $agency->isIndependent));
        $ids = $this->createStub(IdGenerator::class);
        $ids->method('generate')->willReturn('0199d534-0000-7000-8000-000000000101');

        $agency = (new CreateAgency($repository, $ids))->create(
            'independent',
            SupportedLanguage::Japanese,
            true,
            [new AgencyName(SupportedLanguage::Japanese, '個人勢')],
        );

        self::assertSame('0199d534-0000-7000-8000-000000000101', $agency->id);
    }

    #[Test]
    public function itRejectsAnExistingAgencyCode(): void
    {
        $repository = $this->createStub(AgencyRepository::class);
        $repository->method('findByCode')->willReturn(new Agency(
            '0199d534-0000-7000-8000-000000000102',
            'independent',
            SupportedLanguage::Japanese,
            true,
            [new AgencyName(SupportedLanguage::Japanese, '個人勢')],
        ));

        $this->expectException(AgencyAlreadyExists::class);
        (new CreateAgency($repository, $this->createStub(IdGenerator::class)))->create(
            'independent',
            SupportedLanguage::Japanese,
            true,
            [new AgencyName(SupportedLanguage::Japanese, '個人勢')],
        );
    }
}
