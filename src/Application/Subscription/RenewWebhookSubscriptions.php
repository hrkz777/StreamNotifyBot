<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Catalog\PlatformAccount;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\UnsupportedPlatform;
use App\Domain\Subscription\WebhookSubscription;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionRequestDispatcher;
use App\Domain\Subscription\WebhookSubscriptionRequestFailed;
use App\Domain\System\Clock;
use App\Domain\System\IntegerRandomizer;
use App\Domain\System\LeaseTokenGenerator;
use DateInterval;

final readonly class RenewWebhookSubscriptions
{
    public function __construct(
        private WebhookSubscriptionRepository $subscriptionRepository,
        private StreamerCatalogRepository $streamerCatalogRepository,
        private WebhookSubscriptionRequestDispatcher $requestDispatcher,
        private LeaseTokenGenerator $leaseTokenGenerator,
        private IntegerRandomizer $integerRandomizer,
        private Clock $clock,
    ) {
    }

    public function renew(RenewWebhookSubscriptionsInput $input): WebhookSubscriptionRenewalResult
    {
        $startedAt = $this->clock->now();
        $stopStartingAt = $startedAt->add(new DateInterval(sprintf('PT%dS', $input->maxRuntimeSeconds)));
        $claimedCount = 0;
        $acceptedCount = 0;
        $retryScheduledCount = 0;
        $permanentlyFailedCount = 0;
        $releasedCount = 0;
        $staleResultCount = 0;

        if ($this->clock->now() >= $stopStartingAt) {
            return new WebhookSubscriptionRenewalResult(0, 0, 0, 0, 0, 0);
        }

        $subscriptions = $this->subscriptionRepository->claimDue(
            $input->batchSize,
            $this->leaseTokenGenerator->generate(),
            $input->leaseSeconds,
        );
        $claimedCount = count($subscriptions);

        foreach ($subscriptions as $index => $subscription) {
            if ($this->clock->now() >= $stopStartingAt) {
                foreach (array_slice($subscriptions, $index) as $unprocessedSubscription) {
                    if ($this->subscriptionRepository->releaseClaim($unprocessedSubscription)) {
                        ++$releasedCount;
                    } else {
                        ++$staleResultCount;
                    }
                }

                break;
            }

            $account = $this->streamerCatalogRepository->findPlatformAccountById(
                $subscription->platformAccountId,
            );
            $result = $this->request($subscription, $account, $input);

            if (!$this->subscriptionRepository->saveClaimResult($result)) {
                ++$staleResultCount;

                continue;
            }

            if ($result->lastErrorCode === null) {
                ++$acceptedCount;
            } elseif ($result->renewAfter === null) {
                ++$permanentlyFailedCount;
            } else {
                ++$retryScheduledCount;
            }
        }

        return new WebhookSubscriptionRenewalResult(
            $claimedCount,
            $acceptedCount,
            $retryScheduledCount,
            $permanentlyFailedCount,
            $releasedCount,
            $staleResultCount,
        );
    }

    private function request(
        WebhookSubscription $subscription,
        ?PlatformAccount $account,
        RenewWebhookSubscriptionsInput $input,
    ): WebhookSubscription {
        if ($account === null || !$account->isEnabled) {
            return $subscription->failPermanently('platform_account_unavailable');
        }

        try {
            $this->requestDispatcher->requestSubscription($account, $subscription);

            return $subscription->awaitVerification(
                $this->clock->now()->add(new DateInterval(sprintf('PT%dS', $input->verificationRetrySeconds))),
            );
        } catch (WebhookSubscriptionRequestFailed $exception) {
            if (!$exception->retryable || $subscription->failureCount + 1 >= $input->maxAttempts) {
                return $subscription->failPermanently($exception->errorCode);
            }

            return $subscription->scheduleRetry(
                $this->clock->now()->add(new DateInterval(sprintf(
                    'PT%dS',
                    $this->retryDelaySeconds($subscription->failureCount + 1, $input),
                ))),
                $exception->errorCode,
            );
        } catch (UnsupportedPlatform) {
            return $subscription->failPermanently('unsupported_platform');
        }
    }

    private function retryDelaySeconds(int $failureNumber, RenewWebhookSubscriptionsInput $input): int
    {
        $delay = $input->retryInitialDelaySeconds;
        for ($attempt = 1; $attempt < $failureNumber && $delay < $input->retryMaxDelaySeconds; ++$attempt) {
            $delay = min(
                $input->retryMaxDelaySeconds,
                (int) ceil($delay * $input->backoffMultiplier),
            );
        }

        $jitter = (int) floor($delay * $input->jitterPercent / 100);
        if ($jitter === 0) {
            return $delay;
        }

        return max(1, min(
            $input->retryMaxDelaySeconds,
            $delay + $this->integerRandomizer->between(-$jitter, $jitter),
        ));
    }
}
