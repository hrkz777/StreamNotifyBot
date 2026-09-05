<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Job\ConcurrentJobPolicyUpdate;
use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobPolicyNotFound;
use App\Domain\Job\JobType;
use App\Infrastructure\Persistence\DoctrineJobPolicyRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineJobPolicyRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineJobPolicyRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->repository = new DoctrineJobPolicyRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itLoadsAllDocumentedDefaults(): void
    {
        $policies = $this->repository->findAll();

        self::assertCount(5, $policies);
        self::assertSame(50, $this->repository->get(JobType::WebhookEvent)->batchSize);
        self::assertSame(5, $this->repository->get(JobType::StreamPolling)->maxAttempts);
        self::assertSame(20, $this->repository->get(JobType::SubscriptionRenewal)->batchSize);
        self::assertSame(20, $this->repository->get(JobType::Notification)->batchSize);
        self::assertSame(100, $this->repository->get(JobType::Cleanup)->batchSize);

        foreach ($policies as $policy) {
            self::assertSame(45, $policy->maxRuntimeSeconds);
            self::assertSame(60, $policy->retryInitialDelaySeconds);
            self::assertSame(3600, $policy->retryMaxDelaySeconds);
            self::assertSame(2.0, $policy->backoffMultiplier);
            self::assertSame(20, $policy->jitterPercent);
            self::assertSame(120, $policy->leaseSeconds);
            self::assertTrue($policy->isEnabled);
            self::assertSame(0, $policy->lockVersion);
        }
    }

    #[Test]
    public function itUpdatesAPolicyUsingOptimisticLocking(): void
    {
        $current = $this->repository->get(JobType::SubscriptionRenewal);
        $updatedAt = new DateTimeImmutable('2026-09-05 01:00:00.123456+00:00');
        $this->repository->save($this->copyPolicy(
            $current,
            batchSize: 30,
            updatedAt: $updatedAt,
        ));

        $stored = $this->repository->get(JobType::SubscriptionRenewal);

        self::assertSame(30, $stored->batchSize);
        self::assertEquals($updatedAt, $stored->updatedAt);
        self::assertSame(1, $stored->lockVersion);
    }

    #[Test]
    public function itRejectsAStalePolicyUpdate(): void
    {
        $current = $this->repository->get(JobType::SubscriptionRenewal);
        $this->repository->save($this->copyPolicy($current, batchSize: 30));

        $this->expectException(ConcurrentJobPolicyUpdate::class);

        $this->repository->save($this->copyPolicy($current, batchSize: 40));
    }

    #[Test]
    public function itReportsAMissingPolicy(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM job_policies WHERE job_type = ?',
            [JobType::SubscriptionRenewal->value],
        );

        $this->expectException(JobPolicyNotFound::class);

        $this->repository->get(JobType::SubscriptionRenewal);
    }

    private function copyPolicy(
        JobPolicy $policy,
        ?int $batchSize = null,
        ?DateTimeImmutable $updatedAt = null,
    ): JobPolicy {
        return new JobPolicy(
            $policy->id,
            $policy->jobType,
            $batchSize ?? $policy->batchSize,
            $policy->maxRuntimeSeconds,
            $policy->maxAttempts,
            $policy->retryInitialDelaySeconds,
            $policy->retryMaxDelaySeconds,
            $policy->backoffMultiplier,
            $policy->jitterPercent,
            $policy->leaseSeconds,
            $policy->isEnabled,
            $updatedAt ?? $policy->updatedAt,
            $policy->lockVersion,
        );
    }
}
