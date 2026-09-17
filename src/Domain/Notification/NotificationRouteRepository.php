<?php

declare(strict_types=1);

namespace App\Domain\Notification;

interface NotificationRouteRepository
{
    public function add(NotificationRoute $route): void;

    public function saveConfiguration(NotificationRouteConfiguration $configuration): void;

    public function findConfiguration(string $routeId): ?NotificationRouteConfiguration;

    public function remove(string $routeId): void;

    /** @return list<NotificationRoute> */
    public function findAll(): array;
}
