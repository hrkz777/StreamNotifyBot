<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;

interface AdministratorRepository
{
    public function add(Administrator $administrator): void;

    public function findById(string $id): ?Administrator;

    public function findByLoginId(string $loginId): ?Administrator;

    public function markLoggedIn(string $id, int $authenticationVersion, DateTimeImmutable $loggedInAt): bool;

    /** @return list<Administrator> */
    public function findAll(): array;
}
