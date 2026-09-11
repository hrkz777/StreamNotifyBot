<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Subscription\WebhookEventLease;
use App\Domain\Subscription\WebhookEventRepository;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\System\Clock;
use App\Domain\System\LeaseTokenGenerator;
use App\Domain\System\IdGenerator;
use App\Domain\Stream\PlatformVideo;
use App\Domain\Stream\PlatformVideoRepository;
use App\Infrastructure\Platform\YouTube\YouTubeAtomFeedParser;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsProvider;
use App\Infrastructure\Platform\YouTube\YouTubeVideoDetailsUnavailable;
use DateInterval;
use InvalidArgumentException;

final readonly class ProcessWebhookEvents
{
    private const YOUTUBE_CHANNEL_FEED = 'channel.feed';

    public function __construct(
        private WebhookEventRepository $eventRepository,
        private WebhookSubscriptionRepository $subscriptionRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private YouTubeAtomFeedParser $youTubeAtomFeedParser,
        private YouTubeVideoDetailsProvider $youTubeVideoDetailsProvider,
        private PlatformVideoRepository $platformVideoRepository,
        private IdGenerator $idGenerator,
        private LeaseTokenGenerator $leaseTokenGenerator,
        private Clock $clock,
    ) {
    }

    public function process(ProcessWebhookEventsInput $input): WebhookEventProcessingResult
    {
        $stopStartingAt = $this->clock->now()->add(new DateInterval(sprintf('PT%dS', $input->maxRuntimeSeconds)));
        $leases = $this->eventRepository->claimPending($input->batchSize, $this->leaseTokenGenerator->generate(), $input->leaseSeconds);
        $processedCount = 0;
        $discardedCount = 0;
        $releasedCount = 0;
        $staleResultCount = 0;

        foreach ($leases as $index => $lease) {
            if ($this->clock->now() >= $stopStartingAt) {
                foreach (array_slice($leases, $index) as $unprocessedLease) {
                    if ($this->eventRepository->releaseClaim($unprocessedLease)) {
                        ++$releasedCount;
                    } else {
                        ++$staleResultCount;
                    }
                }

                break;
            }

            try {
                $accepted = $this->processYouTubeEvent($lease);
            } catch (YouTubeVideoDetailsUnavailable) {
                if ($this->eventRepository->releaseClaim($lease)) {
                    ++$releasedCount;
                } else {
                    ++$staleResultCount;
                }

                continue;
            } catch (InvalidArgumentException) {
                $accepted = false;
            }

            if (!$this->eventRepository->markProcessed($lease)) {
                ++$staleResultCount;

                continue;
            }

            if ($accepted) {
                ++$processedCount;
            } else {
                ++$discardedCount;
            }
        }

        return new WebhookEventProcessingResult(count($leases), $processedCount, $discardedCount, $releasedCount, $staleResultCount);
    }

    private function processYouTubeEvent(WebhookEventLease $lease): bool
    {
        $subscription = $this->subscriptionRepository->findById($lease->event->subscriptionId);
        if ($subscription === null || $subscription->status !== WebhookSubscriptionStatus::Active || $subscription->subscriptionType !== self::YOUTUBE_CHANNEL_FEED) {
            return false;
        }

        $account = $this->streamerCatalogRepository->findPlatformAccountById($subscription->platformAccountId);
        if ($account === null || !$account->isEnabled || $account->platform !== Platform::YouTube) {
            return false;
        }

        $entries = $this->youTubeAtomFeedParser->parse($lease->event->payload);
        foreach ($entries as $entry) {
            if ($entry->channelId !== $account->externalId) {
                return false;
            }
        }

        $details = $this->youTubeVideoDetailsProvider->fetch(array_map(static fn ($entry): string => $entry->videoId, $entries));
        foreach ($details as $detail) {
            if ($detail->channelId !== $account->externalId) {
                return false;
            }
            $this->platformVideoRepository->save(new PlatformVideo(
                $this->idGenerator->generate(),
                $account->id,
                $detail->videoId,
                $detail->title,
                $detail->publishedAt,
                $detail->scheduledStartAt,
                $detail->actualStartAt,
                $detail->actualEndAt,
                $detail->thumbnailUrl,
                $detail->liveBroadcastContent,
                $this->clock->now(),
            ));
        }

        return true;
    }
}
