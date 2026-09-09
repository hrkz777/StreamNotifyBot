<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use RuntimeException;

final class AgencyAlreadyExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('同じ所属区分コードが既に登録されています。');
    }
}
