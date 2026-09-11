<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Admin;

use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Presentation\Admin\AdminNavigationExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminNavigationExtensionTest extends TestCase
{
    #[Test]
    public function itProvidesPersistedNavigationCountsAsTwigGlobals(): void
    {
        $streamers = $this->createMock(StreamerCatalogRepository::class);
        $streamers->expects(self::once())->method('countStreamers')->willReturn(12);
        $destinations = $this->createMock(NotificationDestinationRepository::class);
        $destinations->expects(self::once())->method('countDestinations')->willReturn(3);

        self::assertSame([
            'admin_navigation_counts' => [
                'streamers' => 12,
                'notification_destinations' => 3,
            ],
        ], (new AdminNavigationExtension($streamers, $destinations))->getGlobals());
    }
}
