<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\NotificationDestinationRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class AdminNavigationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly StreamerCatalogRepository $streamerCatalogRepository,
        private readonly NotificationDestinationRepository $notificationDestinationRepository,
    ) {
    }

    /** @return array<string, array{streamers: int, notification_destinations: int}> */
    public function getGlobals(): array
    {
        return [
            'admin_navigation_counts' => [
                'streamers' => $this->streamerCatalogRepository->countStreamers(),
                'notification_destinations' => $this->notificationDestinationRepository->countDestinations(),
            ],
        ];
    }
}
