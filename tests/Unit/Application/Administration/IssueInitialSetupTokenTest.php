<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\IssueInitialSetupToken;
use App\Domain\Administration\AdministratorSetupTokenGenerator;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Domain\Administration\AdministratorTokenRepository;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IssueInitialSetupTokenTest extends TestCase
{
    private const string TOKEN = 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE';
    private const string TOKEN_ID = '01990d4a-0000-7000-8000-000000000170';

    private AuthenticationPolicyRepository&MockObject $authenticationPolicyRepository;
    private AdministratorTokenRepository&MockObject $administratorTokenRepository;
    private AdministratorSetupTokenGenerator&MockObject $tokenGenerator;
    private IdGenerator&MockObject $idGenerator;
    private Clock&MockObject $clock;

    protected function setUp(): void
    {
        $this->authenticationPolicyRepository = $this->createMock(AuthenticationPolicyRepository::class);
        $this->administratorTokenRepository = $this->createMock(AdministratorTokenRepository::class);
        $this->tokenGenerator = $this->createMock(AdministratorSetupTokenGenerator::class);
        $this->idGenerator = $this->createMock(IdGenerator::class);
        $this->clock = $this->createMock(Clock::class);
    }

    #[Test]
    public function itIssuesA30MinuteTokenAndStoresOnlyItsHash(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-08 00:00:00.123456+00:00');
        $this->authenticationPolicyRepository->expects(self::once())->method('get')->willReturn($this->policy(null));
        $this->tokenGenerator->expects(self::once())->method('generate')->willReturn(self::TOKEN);
        $this->clock->expects(self::once())->method('now')->willReturn($createdAt);
        $this->idGenerator->expects(self::once())->method('generate')->willReturn(self::TOKEN_ID);
        $this->administratorTokenRepository->expects(self::once())
            ->method('add')
            ->with(self::callback(static function (AdministratorToken $token) use ($createdAt): bool {
                self::assertSame(self::TOKEN_ID, $token->id);
                self::assertNull($token->administratorId);
                self::assertSame(AdministratorTokenPurpose::InitialSetup, $token->purpose);
                self::assertSame(hash('sha256', self::TOKEN), $token->tokenHash);
                self::assertNull($token->createdByAdministratorId);
                self::assertNull($token->authenticationVersion);
                self::assertSame($createdAt, $token->createdAt);
                self::assertEquals($createdAt->modify('+30 minutes'), $token->expiresAt);
                self::assertNull($token->consumedAt);
                self::assertNull($token->revokedAt);

                return true;
            }));

        $issuedToken = $this->service()->issue();

        self::assertSame(self::TOKEN, $issuedToken->consumeToken());
        self::assertEquals($createdAt->modify('+30 minutes'), $issuedToken->expiresAt);
    }

    #[Test]
    public function itRefusesToIssueAfterInitialSetupCompleted(): void
    {
        $this->authenticationPolicyRepository->expects(self::once())
            ->method('get')
            ->willReturn($this->policy(new DateTimeImmutable('2026-09-07 00:00:00+00:00')));
        $this->tokenGenerator->expects(self::never())->method('generate');
        $this->clock->expects(self::never())->method('now');
        $this->idGenerator->expects(self::never())->method('generate');
        $this->administratorTokenRepository->expects(self::never())->method('add');
        $this->expectException(InitialSetupAlreadyCompleted::class);

        $this->service()->issue();
    }

    private function service(): IssueInitialSetupToken
    {
        return new IssueInitialSetupToken(
            $this->authenticationPolicyRepository,
            $this->administratorTokenRepository,
            $this->tokenGenerator,
            $this->idGenerator,
            $this->clock,
        );
    }

    private function policy(?DateTimeImmutable $initialSetupCompletedAt): AuthenticationPolicy
    {
        return new AuthenticationPolicy(
            AuthenticationPolicy::ID,
            30,
            12,
            10,
            15,
            5,
            15,
            $initialSetupCompletedAt,
            new DateTimeImmutable('2026-09-08 00:00:00+00:00'),
            0,
        );
    }
}
