<?php

declare(strict_types=1);

namespace App\Domain\Stream;

interface NotificationDestinationRepository
{
    public function save(NotificationDestination $destination): void;

    /** @return list<NotificationDestination> */
    public function findAll(): array;

    public function setEnabled(string $id, bool $isEnabled): bool;

    /** @return list<NotificationDestination> */
    public function findEnabledByType(StreamNotificationType $type): array;
}
