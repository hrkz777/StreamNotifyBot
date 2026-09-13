<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

interface AgencyRepository
{
    public function add(Agency $agency): void;

    /** @return list<Agency> */
    public function findAll(): array;

    /** @param iterable<Agency> $agencies */
    public function replaceFromCsv(iterable $agencies, bool $replaceMissing): void;

    public function findById(string $id): ?Agency;

    public function findByCode(string $code): ?Agency;
}
