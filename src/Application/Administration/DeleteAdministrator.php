<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorDeletionRepository;
use App\Domain\System\Clock;

final readonly class DeleteAdministrator
{
    public function __construct(
        private AdministratorDeletionRepository $repository,
        private Clock $clock,
    ) {
    }

    public function delete(string $administratorId): bool
    {
        return $this->repository->delete($administratorId, $this->clock->now());
    }
}
