<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Catalog\RegisterStreamer;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\ResolvedPlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use App\Presentation\Console\RegisterStreamerCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RegisterStreamerCommandTest extends TestCase
{
    #[Test]
    public function itRegistersAnEnabledStreamerFromCommandArguments(): void
    {
        $repository = $this->createMock(StreamerCatalogRepository::class);
        $repository->expects(self::once())->method('register');
        $tester = new CommandTester(new RegisterStreamerCommand($this->registerStreamer($repository)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'agency-id' => '01990d4a-0000-7000-8000-000000000301',
            'platform' => 'YouTube',
            'registration-identifier' => '@channel',
            'name' => '配信者名',
            '--color' => '#123456',
        ]));
        self::assertStringContainsString('01990d4a-0000-7000-8000-000000000302', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsAnUnsupportedPlatformBeforeRegistration(): void
    {
        $repository = $this->createMock(StreamerCatalogRepository::class);
        $repository->expects(self::never())->method('register');
        $tester = new CommandTester(new RegisterStreamerCommand($this->registerStreamer($repository)));

        self::assertSame(Command::INVALID, $tester->execute([
            'agency-id' => '01990d4a-0000-7000-8000-000000000301',
            'platform' => 'unknown',
            'registration-identifier' => 'channel',
            'name' => '配信者名',
        ]));
        self::assertStringContainsString('対応していないプラットフォームです。', $tester->getDisplay());
    }

    private function registerStreamer(StreamerCatalogRepository $repository): RegisterStreamer
    {
        $agencyRepository = $this->createStub(AgencyRepository::class);
        $agencyRepository->method('findById')->willReturn(new Agency(
            '01990d4a-0000-7000-8000-000000000301',
            'independent',
            SupportedLanguage::Japanese,
            true,
            [new AgencyName(SupportedLanguage::Japanese, '個人勢')],
        ));
        $lookup = $this->createStub(PlatformAccountLookup::class);
        $lookup->method('resolve')->willReturn(new ResolvedPlatformAccount(
            'UC_RESOLVED',
            '@channel',
            '配信者名',
            'https://www.youtube.com/@channel',
            null,
            null,
            null,
        ));
        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator->method('generate')->willReturnOnConsecutiveCalls(
            '01990d4a-0000-7000-8000-000000000302',
            '01990d4a-0000-7000-8000-000000000303',
            '01990d4a-0000-7000-8000-000000000304',
        );
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08 00:00:00+00:00'));

        return new RegisterStreamer($agencyRepository, $repository, $lookup, $idGenerator, $clock);
    }
}
