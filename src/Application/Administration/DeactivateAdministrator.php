<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorDeactivationRepository;
use App\Domain\System\Clock;

final readonly class DeactivateAdministrator
{
    public function __construct(
        private AdministratorDeactivationRepository $repository,
        private Clock $clock,
    ) {
    }

    public function deactivate(string $administratorId): bool
    {
        return $this->repository->deactivate($administratorId, $this->clock->now());
    }
}
