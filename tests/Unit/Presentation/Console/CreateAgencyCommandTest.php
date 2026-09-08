<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Catalog\CreateAgency;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\System\IdGenerator;
use App\Presentation\Console\CreateAgencyCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAgencyCommandTest extends TestCase
{
    #[Test]
    public function itCreatesAnIndependentAgencyFromCommandArguments(): void
    {
        $repository = $this->createMock(AgencyRepository::class);
        $repository->expects(self::once())
            ->method('findByCode')
            ->with('independent')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('add')
            ->with(self::callback(static function (Agency $agency): bool {
                self::assertSame('independent', $agency->code);
                self::assertTrue($agency->isIndependent);
                self::assertSame('個人勢', $agency->nameFor($agency->defaultLanguage)->name);
                self::assertSame('個人', $agency->nameFor($agency->defaultLanguage)->shortName);

                return true;
            }));
        $tester = new CommandTester(new CreateAgencyCommand(new CreateAgency($repository, $this->idGenerator())));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'code' => 'independent',
            'name' => '個人勢',
            '--short-name' => '個人',
            '--independent' => true,
        ]));
        self::assertStringContainsString('所属区分「個人」', $tester->getDisplay());
    }

    #[Test]
    public function itReportsInvalidLanguageAsInvalidInput(): void
    {
        $repository = $this->createMock(AgencyRepository::class);
        $repository->expects(self::never())->method('findByCode');
        $tester = new CommandTester(new CreateAgencyCommand(new CreateAgency($repository, $this->idGenerator())));

        self::assertSame(Command::INVALID, $tester->execute([
            'code' => 'independent',
            'name' => 'Independent',
            '--language' => 'fr',
        ]));
        self::assertStringContainsString('対応していない言語コードです。', $tester->getDisplay());
    }

    private function idGenerator(): IdGenerator
    {
        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator->method('generate')->willReturn('018f6f72-3c2f-7000-8000-000000000000');

        return $idGenerator;
    }
}
