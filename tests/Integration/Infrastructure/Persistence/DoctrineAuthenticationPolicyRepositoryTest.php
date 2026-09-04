<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\ConcurrentAuthenticationPolicyUpdate;
use App\Infrastructure\Persistence\DoctrineAuthenticationPolicyRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAuthenticationPolicyRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineAuthenticationPolicyRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineAuthenticationPolicyRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itLoadsTheAuthenticationPolicy(): void
    {
        $policy = $this->repository->get();

        self::assertSame(AuthenticationPolicy::ID, $policy->id);
        self::assertSame(30, $policy->idleTimeoutMinutes);
        self::assertSame(12, $policy->absoluteTimeoutHours);
        self::assertSame(0, $policy->lockVersion);
        self::assertNull($policy->initialSetupCompletedAt);
    }

    #[Test]
    public function itUpdatesThePolicyUsingOptimisticLocking(): void
    {
        $current = $this->repository->get();
        $updated = $this->copyPolicy(
            $current,
            idleTimeoutMinutes: 45,
            updatedAt: $this->dateTime('2026-09-04 01:00:00.123456'),
        );

        $this->repository->save($updated);

        $stored = $this->repository->get();
        self::assertSame(45, $stored->idleTimeoutMinutes);
        self::assertSame(1, $stored->lockVersion);
        self::assertSame('2026-09-04 01:00:00.123456', $stored->updatedAt->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function itRejectsAStalePolicyUpdate(): void
    {
        $current = $this->repository->get();
        $this->repository->save($this->copyPolicy($current, idleTimeoutMinutes: 45));

        $this->expectException(ConcurrentAuthenticationPolicyUpdate::class);

        $this->repository->save($this->copyPolicy($current, idleTimeoutMinutes: 60));
    }

    #[Test]
    public function itDoesNotClearACompletedInitialSetup(): void
    {
        $current = $this->repository->get();
        $completed = $this->copyPolicy(
            $current,
            initialSetupCompletedAt: $this->dateTime('2026-09-04 02:00:00.000000'),
        );
        $this->repository->save($completed);
        $stored = $this->repository->get();

        $this->expectException(ConcurrentAuthenticationPolicyUpdate::class);

        $this->repository->save($this->copyPolicy($stored, initialSetupCompletedAt: null));
    }

    private function copyPolicy(
        AuthenticationPolicy $policy,
        ?int $idleTimeoutMinutes = null,
        ?DateTimeImmutable $initialSetupCompletedAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): AuthenticationPolicy {
        return new AuthenticationPolicy(
            id: $policy->id,
            idleTimeoutMinutes: $idleTimeoutMinutes ?? $policy->idleTimeoutMinutes,
            absoluteTimeoutHours: $policy->absoluteTimeoutHours,
            reauthenticationMinutes: $policy->reauthenticationMinutes,
            failureWindowMinutes: $policy->failureWindowMinutes,
            failureThreshold: $policy->failureThreshold,
            maximumDelayMinutes: $policy->maximumDelayMinutes,
            initialSetupCompletedAt: $initialSetupCompletedAt,
            updatedAt: $updatedAt ?? $policy->updatedAt,
            lockVersion: $policy->lockVersion,
        );
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
