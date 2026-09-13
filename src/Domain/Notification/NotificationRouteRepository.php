<?php

declare(strict_types=1);

namespace App\Domain\Notification;

interface NotificationRouteRepository
{
    public function add(NotificationRoute $route): void;

    /** @return list<NotificationRoute> */
    public function findAll(): array;
}
