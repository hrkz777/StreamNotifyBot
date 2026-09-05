<?php

declare(strict_types=1);

namespace App\Domain\Administration;

interface AdministratorRepository
{
    public function add(Administrator $administrator): void;

    public function findById(string $id): ?Administrator;

    public function findByLoginId(string $loginId): ?Administrator;
}
