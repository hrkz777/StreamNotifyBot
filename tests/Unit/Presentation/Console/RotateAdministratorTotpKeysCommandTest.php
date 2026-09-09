<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Domain\Administration\AdministratorTotpCredentialReencryptor;
use App\Presentation\Console\RotateAdministratorTotpKeysCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RotateAdministratorTotpKeysCommandTest extends TestCase
{
    #[Test]
    public function itDoesNotRotateWithoutAnExplicitExecuteOption(): void
    {
        $reencryptor = $this->createMock(AdministratorTotpCredentialReencryptor::class);
        $reencryptor->expects(self::never())->method('reencrypt');
        $tester = new CommandTester(new RotateAdministratorTotpKeysCommand($reencryptor));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('--execute', $tester->getDisplay());
    }

    #[Test]
    public function itReencryptsOnlyWhenExplicitlyRequested(): void
    {
        $reencryptor = $this->createMock(AdministratorTotpCredentialReencryptor::class);
        $reencryptor->expects(self::once())->method('reencrypt')->willReturn(3);
        $tester = new CommandTester(new RotateAdministratorTotpKeysCommand($reencryptor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true]));
        self::assertStringContainsString('3件', $tester->getDisplay());
    }
}
