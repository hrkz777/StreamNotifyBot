<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\IssueAdministratorInvitation;
use App\Domain\Administration\AdministratorInvitationRepository;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorSetupTokenGenerator;
use App\Domain\Administration\AdministratorToken;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueAdministratorInvitationTest extends TestCase
{
    #[Test]
    public function itIssuesA30MinuteInvitationAndStoresOnlyItsHash(): void
    {
        $repository = $this->createMock(AdministratorInvitationRepository::class);
        $generator = $this->createStub(AdministratorSetupTokenGenerator::class);
        $generator->method('generate')->willReturn('plain-invitation-token');
        $ids = $this->createStub(IdGenerator::class);
        $ids->method('generate')->willReturn('01990d4a-0000-7000-8000-000000000190', '01990d4a-0000-7000-8000-000000000191');
        $clock = $this->createStub(Clock::class);
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $clock->method('now')->willReturn($now);
        $repository->expects(self::once())->method('create')->with(self::anything(), self::callback(static fn (AdministratorToken $token): bool => $token->tokenHash === hash('sha256', 'plain-invitation-token') && $token->expiresAt == $now->modify('+30 minutes')));

        $result = (new IssueAdministratorInvitation($repository, $generator, $ids, $clock))->issue('01990d4a-0000-7000-8000-000000000001', 'invited.admin', '招待管理者', AdministratorRole::Administrator);

        self::assertSame('plain-invitation-token', $result->consumeToken());
    }
}
