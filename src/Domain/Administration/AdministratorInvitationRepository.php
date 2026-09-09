<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorInvitationRepository
{
    public function create(Administrator $administrator, AdministratorToken $token): void;
}
