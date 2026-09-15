<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionTypeCatalog;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use InvalidArgumentException;

final readonly class AddPlatformAccountToStreamer
{
    public function __construct(
        private StreamerCatalogRepository $streamers,
        private PlatformAccountLookup $platformAccountLookup,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function add(string $streamerId, Platform $platform, string $registrationIdentifier): void
    {
        if ($this->streamers->findStreamerById($streamerId) === null) {
            throw new InvalidArgumentException('追加先の配信者が見つかりません。画面を更新して選び直してください。');
        }

        $resolved = $this->platformAccountLookup->resolve($platform, $registrationIdentifier);
        if ($this->streamers->findPlatformAccountByExternalId($platform, $resolved->externalId) !== null) {
            throw new InvalidArgumentException('このプラットフォームアカウントは既に登録されています。');
        }

        $now = $this->clock->now();
        $accountId = $this->idGenerator->generate();
        $account = new PlatformAccount(
            $accountId,
            $streamerId,
            $platform,
            $resolved->externalId,
            $registrationIdentifier,
            $resolved->displayId,
            $resolved->name,
            $resolved->profileUrl,
            $resolved->iconUrl,
            $resolved->offlineImageUrl,
            true,
            $now,
            $now,
            $resolved->apiDataExpiresAt,
        );
        $subscriptions = array_map(
            fn (string $subscriptionType) => WebhookSubscription::pending(
                $this->idGenerator->generate(),
                $accountId,
                $subscriptionType,
                $now,
            ),
            WebhookSubscriptionTypeCatalog::forPlatform($platform),
        );
        $this->streamers->addPlatformAccountWithSubscriptions($account, $subscriptions);
    }
}
