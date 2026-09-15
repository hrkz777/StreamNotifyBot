<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionTypeCatalog;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use InvalidArgumentException;

final readonly class RegisterStreamersFromCsv
{
    public function __construct(private ImportStreamerCsv $importer, private StreamerCatalogRepository $streamers, private PlatformAccountLookup $lookup, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function execute(string $csv): int
    {
        $registrations = $this->importer->preview($csv);
        $pending = [];
        /** @var array<string, true> $externalAccountKeys */
        $externalAccountKeys = [];
        foreach ($registrations as $registration) {
            $streamerId = $this->ids->generate();
            $now = $this->clock->now();
            $accounts = [];
            $subscriptions = [];
            foreach ($registration->accounts as $input) {
                $resolved = $this->lookup->resolve($input->platform, $input->registrationIdentifier);
                if ($this->streamers->findPlatformAccountByExternalId($input->platform, $resolved->externalId) !== null) {
                    throw new InvalidArgumentException(sprintf('配信者「%s」の%sアカウントは既に登録されています。', $registration->key, $input->platform->displayId()));
                }
                $externalAccountKey = $input->platform->value . "\0" . $resolved->externalId;
                if (isset($externalAccountKeys[$externalAccountKey])) {
                    throw new InvalidArgumentException(sprintf('配信者「%s」の%sアカウントがCSV内で重複しています。', $registration->key, $input->platform->displayId()));
                }
                $externalAccountKeys[$externalAccountKey] = true;
                $accountId = $this->ids->generate();
                $accounts[] = new PlatformAccount($accountId, $streamerId, $input->platform, $resolved->externalId, $input->registrationIdentifier, $resolved->displayId, $resolved->name, $resolved->profileUrl, $resolved->iconUrl, $resolved->offlineImageUrl, $input->platformAccountEnabled, $now, $now, $resolved->apiDataExpiresAt);
                foreach (WebhookSubscriptionTypeCatalog::forPlatform($input->platform) as $type) {
                    $subscriptions[] = WebhookSubscription::pending($this->ids->generate(), $accountId, $type, $now);
                }
            }
            $pending[] = [new Streamer($streamerId, $registration->agencyId, $registration->defaultLanguage, $registration->colorCode, $registration->isEnabled, $registration->names), $accounts, $subscriptions];
        }
        foreach ($pending as [$streamer, $accounts, $subscriptions]) {
            $this->streamers->registerWithAccounts($streamer, $accounts, $subscriptions);
        }
        return count($registrations);
    }
}
