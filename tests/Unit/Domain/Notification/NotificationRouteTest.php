<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Notification;

use App\Domain\Notification\NotificationRoute;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRouteTest extends TestCase
{
    #[Test]
    public function itNormalizesItsTextFields(): void
    {
        $route = new NotificationRoute('01990d4a-0000-7000-8000-000000000401', ' 通知設定 ', ' 用途 ', 'purple', true);

        self::assertSame('通知設定', $route->name);
        self::assertSame('用途', $route->description);
    }

    #[Test]
    public function itRejectsAnUnsupportedColor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationRoute('01990d4a-0000-7000-8000-000000000401', '通知設定', null, 'red', true);
    }
}
