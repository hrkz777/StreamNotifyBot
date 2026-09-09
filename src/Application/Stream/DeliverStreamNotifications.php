<?php

declare(strict_types=1);

namespace App\Application\Stream;

use App\Domain\Stream\NotificationDestinationRepository;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\StreamNotificationOutboxLease;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\System\Clock;
use App\Domain\System\LeaseTokenGenerator;
use App\Infrastructure\Notification\DiscordStreamNotificationPayloadFactory;

final readonly class DeliverStreamNotifications
{
    public function __construct(
        private StreamNotificationOutboxRepository $outboxRepository,
        private PlatformVideoRepository $platformVideoRepository,
        private NotificationDestinationRepository $notificationDestinationRepository,
        private DiscordStreamNotificationPayloadFactory $payloadFactory,
        private SendDiscordNotification $sendDiscordNotification,
        private LeaseTokenGenerator $leaseTokenGenerator,
        private Clock $clock,
    ) {
    }

    public function deliver(DeliverStreamNotificationsInput $input): StreamNotificationDeliveryResult
    {
        $leases = $this->outboxRepository->claimPending($input->batchSize, $this->leaseTokenGenerator->generate(), $input->leaseSeconds);
        $sentCount = 0;
        $suppressedCount = 0;
        $releasedCount = 0;
        $staleResultCount = 0;

        foreach ($leases as $lease) {
            $outcome = $this->deliverLease($lease);
            if ($outcome === 'sent') {
                ++$sentCount;
            } elseif ($outcome === 'suppressed') {
                ++$suppressedCount;
            } elseif ($outcome === 'released') {
                ++$releasedCount;
            } else {
                ++$staleResultCount;
            }
        }

        return new StreamNotificationDeliveryResult(count($leases), $sentCount, $suppressedCount, $releasedCount, $staleResultCount);
    }

    private function deliverLease(StreamNotificationOutboxLease $lease): string
    {
        $video = $this->platformVideoRepository->findById($lease->notification->platformVideoId);
        $destinations = $video === null ? [] : $this->notificationDestinationRepository->findEnabledByType($lease->notification->type);
        if ($video === null || $destinations === []) {
            return $this->outboxRepository->suppress($lease) ? 'suppressed' : 'stale';
        }

        $payload = $this->payloadFactory->create($lease->notification->type, $video, $this->clock->now());
        try {
            foreach ($destinations as $destination) {
                $this->sendDiscordNotification->send($destination, $payload);
            }
        } catch (\Throwable) {
            return $this->outboxRepository->releaseClaim($lease) ? 'released' : 'stale';
        }

        return $this->outboxRepository->markSent($lease) ? 'sent' : 'stale';
    }
}
