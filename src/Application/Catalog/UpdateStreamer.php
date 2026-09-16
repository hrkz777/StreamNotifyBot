<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Streamer;
use App\Domain\Catalog\StreamerCatalogRepository;

final readonly class UpdateStreamer
{
    public function __construct(
        private AgencyRepository $agencies,
        private StreamerCatalogRepository $streamers,
    ) {
    }

    public function update(Streamer $streamer): void
    {
        if ($this->agencies->findById($streamer->agencyId) === null) {
            throw new AgencyNotFound();
        }

        if ($this->streamers->findStreamerById($streamer->id) === null) {
            throw new StreamerNotFound();
        }

        $this->streamers->updateStreamer($streamer);
    }
}
