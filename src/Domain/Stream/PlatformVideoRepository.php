<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface PlatformVideoRepository
{
    public function save(PlatformVideo $video): void;
}
