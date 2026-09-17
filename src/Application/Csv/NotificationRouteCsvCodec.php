<?php

declare(strict_types=1);

namespace App\Application\Csv;

use App\Domain\Notification\NotificationRoute;

final readonly class NotificationRouteCsvCodec
{
    /** @var list<string> */
    public const array HEADERS = ['notification_route_id', 'name', 'description', 'color', 'is_enabled'];

    /** @param iterable<NotificationRoute> $routes */
    public function export(iterable $routes, CsvExportEncoding $encoding = CsvExportEncoding::ShiftJis): string
    {
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [$route->id, $route->name, $route->description ?? '', $route->color, $route->isEnabled ? '1' : '0'];
        }

        return CsvEncoder::encode(self::HEADERS, $rows, encoding: $encoding);
    }
}
