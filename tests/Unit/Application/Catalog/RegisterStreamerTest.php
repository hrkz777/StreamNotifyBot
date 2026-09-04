<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\AgencyNotFound;
use App\Application\Catalog\RegisterStreamer;
use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\ResolvedPlatformAccount;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterStreamerTest extends TestCase
{
    private const AGENCY_ID = '01990d4a-0000-7000-8000-000000000301';
    private const STREAMER_ID = '01990d4a-0000-7000-8000-000000000302';
    private const ACCOUNT_ID = '01990d4a-0000-7000-8000-000000000303';
    private const SUBSCRIPTION_ID = '01990d4a-0000-7000-8000-000000000304';

    #[Test]
    public function itResolvesTheExternalAccountBeforePersistingTheRegistration(): void
    {
        $agencyRepository = $this->createMock(AgencyRepository::class);
        $agencyRepository
            ->expects($this->once())
            ->method('findById')
            ->with(self::AGENCY_ID)
            ->willReturn($this->agency());

        $lookup = $this->createMock(PlatformAccountLookup::class);
        $lookup
            ->expects($this->once())
            ->method('resolve')
            ->with(Platform::YouTube, '@input')
            ->willReturn(new ResolvedPlatformAccount(
                'UC_RESOLVED',
                '@resolved',
                'Resolved Channel',
                'https://www.youtube.com/@resolved',
                'https://example.com/icon.png',
                null,
                new DateTimeImmutable('2026-10-02 00:00:00+00:00'),
            ));

        $idGenerator = $this->createStub(IdGenerator::class);
        $idGenerator
            ->method('generate')
            ->willReturnOnConsecutiveCalls(self::STREAMER_ID, self::ACCOUNT_ID, self::SUBSCRIPTION_ID);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02 00:00:00+00:00'));

        $repository = $this->createMock(StreamerCatalogRepository::class);
        $repository
            ->expects($this->once())
            ->method('register')
            ->with(
                $this->callback(static fn (Streamer $streamer): bool => $streamer->id === self::STREAMER_ID),
                $this->callback(static fn (PlatformAccount $account): bool => (
                    $account->id === self::ACCOUNT_ID
                    && $account->externalId === 'UC_RESOLVED'
                    && $account->registrationIdentifier === '@input'
                    && $account->displayId === '@resolved'
                    && $account->name === 'Resolved Channel'
                )),
                $this->callback(static function (iterable $subscriptions): bool {
                    $items = array_values([...$subscriptions]);

                    return count($items) === 1
                        && $items[0] instanceof WebhookSubscription
                        && $items[0]->id === self::SUBSCRIPTION_ID
                        && $items[0]->platformAccountId === self::ACCOUNT_ID
                        && $items[0]->subscriptionType === 'channel.feed'
                        && $items[0]->status === WebhookSubscriptionStatus::Pending
                        && $items[0]->renewAfter?->format('Y-m-d H:i:s') === '2026-09-02 00:00:00';
                }),
            );

        $result = (new RegisterStreamer(
            $agencyRepository,
            $repository,
            $lookup,
            $idGenerator,
            $clock,
        ))->register($this->input());

        self::assertSame(self::STREAMER_ID, $result->streamerId);
        self::assertSame(self::ACCOUNT_ID, $result->platformAccountId);
    }

    #[Test]
    public function itStopsBeforeExternalLookupWhenTheAgencyDoesNotExist(): void
    {
        $agencyRepository = $this->createStub(AgencyRepository::class);
        $agencyRepository->method('findById')->willReturn(null);
        $lookup = $this->createMock(PlatformAccountLookup::class);
        $lookup->expects($this->never())->method('resolve');
        $repository = $this->createMock(StreamerCatalogRepository::class);
        $repository->expects($this->never())->method('register');

        $this->expectException(AgencyNotFound::class);

        (new RegisterStreamer(
            $agencyRepository,
            $repository,
            $lookup,
            $this->createStub(IdGenerator::class),
            $this->createStub(Clock::class),
        ))->register($this->input());
    }

    private function agency(): Agency
    {
        return new Agency(
            self::AGENCY_ID,
            'example',
            SupportedLanguage::Japanese,
            false,
            [new AgencyName(SupportedLanguage::Japanese, '所属区分')],
        );
    }

    private function input(): RegisterStreamerInput
    {
        return new RegisterStreamerInput(
            self::AGENCY_ID,
            SupportedLanguage::Japanese,
            '#123456',
            true,
            [new StreamerName(SupportedLanguage::Japanese, '配信者')],
            Platform::YouTube,
            '　@input　',
        );
    }
}
