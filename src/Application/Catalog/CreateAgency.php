<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\Agency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\System\IdGenerator;

final readonly class CreateAgency
{
    public function __construct(
        private AgencyRepository $agencyRepository,
        private IdGenerator $idGenerator,
    ) {
    }

    /** @param iterable<AgencyName> $names */
    public function create(
        string $code,
        SupportedLanguage $defaultLanguage,
        bool $isIndependent,
        iterable $names,
    ): Agency {
        if ($this->agencyRepository->findByCode($code) !== null) {
            throw new AgencyAlreadyExists();
        }

        $agency = new Agency(
            $this->idGenerator->generate(),
            $code,
            $defaultLanguage,
            $isIndependent,
            $names,
        );
        $this->agencyRepository->add($agency);

        return $agency;
    }
}
