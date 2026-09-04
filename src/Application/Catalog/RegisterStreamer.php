<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionTypeCatalog;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;

final readonly class RegisterStreamer
{
    public function __construct(
        private AgencyRepository $agencyRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private PlatformAccountLookup $platformAccountLookup,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function register(RegisterStreamerInput $input): RegisteredStreamer
    {
        if ($this->agencyRepository->findById($input->agencyId) === null) {
            throw new AgencyNotFound();
        }

        $resolvedAccount = $this->platformAccountLookup->resolve(
            $input->platform,
            $input->registrationIdentifier,
        );
        $resolvedAt = $this->clock->now();
        $streamerId = $this->idGenerator->generate();
        $platformAccountId = $this->idGenerator->generate();

        $streamer = new Streamer(
            $streamerId,
            $input->agencyId,
            $input->defaultLanguage,
            $input->colorCode,
            $input->isEnabled,
            $input->names,
        );
        $account = new PlatformAccount(
            $platformAccountId,
            $streamerId,
            $input->platform,
            $resolvedAccount->externalId,
            $input->registrationIdentifier,
            $resolvedAccount->displayId,
            $resolvedAccount->name,
            $resolvedAccount->profileUrl,
            $resolvedAccount->iconUrl,
            $resolvedAccount->offlineImageUrl,
            true,
            $resolvedAt,
            $resolvedAt,
            $resolvedAccount->apiDataExpiresAt,
        );

        $subscriptions = array_map(
            fn (string $subscriptionType): WebhookSubscription => WebhookSubscription::pending(
                $this->idGenerator->generate(),
                $platformAccountId,
                $subscriptionType,
                $resolvedAt,
            ),
            WebhookSubscriptionTypeCatalog::forPlatform($input->platform),
        );

        $this->streamerCatalogRepository->register($streamer, $account, $subscriptions);

        return new RegisteredStreamer($streamerId, $platformAccountId);
    }
}
