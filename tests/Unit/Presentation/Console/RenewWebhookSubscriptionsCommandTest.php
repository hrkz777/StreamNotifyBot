<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Console;

use App\Application\Subscription\RenewWebhookSubscriptions;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionRequestDispatcher;
use App\Domain\System\Clock;
use App\Domain\System\IntegerRandomizer;
use App\Domain\System\LeaseTokenGenerator;
use App\Presentation\Console\RenewWebhookSubscriptionsCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RenewWebhookSubscriptionsCommandTest extends TestCase
{
    private const LEASE_TOKEN = '00112233445566778899aabbccddeeff';

    #[Test]
    public function itUsesTheStoredPolicyAndReportsTheResult(): void
    {
        $subscriptionRepository = $this->createMock(WebhookSubscriptionRepository::class);
        $subscriptionRepository
            ->expects($this->once())
            ->method('claimDue')
            ->with(20, self::LEASE_TOKEN, 120)
            ->willReturn([]);

        $tester = $this->tester(true, $subscriptionRepository);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('取得: 0', $tester->getDisplay());
        self::assertStringContainsString('競合破棄: 0', $tester->getDisplay());
    }

    #[Test]
    public function itDoesNotClaimWorkWhenTheStoredPolicyIsDisabled(): void
    {
        $subscriptionRepository = $this->createMock(WebhookSubscriptionRepository::class);
        $subscriptionRepository->expects($this->never())->method('claimDue');

        $tester = $this->tester(false, $subscriptionRepository);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Webhook購読更新ジョブは無効です', $tester->getDisplay());
    }

    private function tester(
        bool $isEnabled,
        WebhookSubscriptionRepository&MockObject $subscriptionRepository,
    ): CommandTester {
        $jobPolicyRepository = $this->createMock(JobPolicyRepository::class);
        $jobPolicyRepository
            ->expects($this->once())
            ->method('get')
            ->with(JobType::SubscriptionRenewal)
            ->willReturn($this->policy($isEnabled));

        $streamerCatalogRepository = $this->createStub(StreamerCatalogRepository::class);
        $requestDispatcher = $this->createStub(WebhookSubscriptionRequestDispatcher::class);
        $leaseTokenGenerator = $this->createStub(LeaseTokenGenerator::class);
        $leaseTokenGenerator->method('generate')->willReturn(self::LEASE_TOKEN);
        $integerRandomizer = $this->createStub(IntegerRandomizer::class);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-05 00:00:00+00:00'));
        $renewalService = new RenewWebhookSubscriptions(
            $subscriptionRepository,
            $streamerCatalogRepository,
            $requestDispatcher,
            $leaseTokenGenerator,
            $integerRandomizer,
            $clock,
        );

        return new CommandTester(new RenewWebhookSubscriptionsCommand(
            $jobPolicyRepository,
            $renewalService,
        ));
    }

    private function policy(bool $isEnabled): JobPolicy
    {
        return new JobPolicy(
            '01990d4a-0000-7000-8000-000000000403',
            JobType::SubscriptionRenewal,
            20,
            45,
            8,
            60,
            3600,
            2.0,
            20,
            120,
            $isEnabled,
            new DateTimeImmutable('2026-09-05 00:00:00+00:00'),
            0,
        );
    }
}
