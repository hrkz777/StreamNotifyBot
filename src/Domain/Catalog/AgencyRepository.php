<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface AgencyRepository
{
    public function add(Agency $agency): void;

    public function findById(string $id): ?Agency;

    public function findByCode(string $code): ?Agency;

    /** @return list<Agency> */
    public function findAll(): array;
}
