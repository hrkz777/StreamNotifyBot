<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use RuntimeException;

final class AgencyNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('指定された所属区分が見つかりません。');
    }
}
