<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccount;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformAccountTest extends TestCase
{
    #[Test]
    public function itKeepsTheDisplayIdentifierSeparateFromTheAccountName(): void
    {
        $account = $this->account();

        self::assertSame('@example', $account->displayId);
        self::assertSame('Example Channel', $account->name);
        self::assertSame('2026-09-01T15:00:00+00:00', $account->resolvedAt->format(DATE_ATOM));
    }

    #[Test]
    public function itRejectsAUrlContainingUserInformation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->account('https://user:password@example.com/channel');
    }

    #[Test]
    public function itRejectsANonAsciiExternalIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000111',
            '01990d4a-0000-7000-8000-000000000101',
            Platform::YouTube,
            '外部ID',
            '@example',
            '@example',
            'Example Channel',
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-02 00:00:00+09:00'),
        );
    }

    #[Test]
    public function itRejectsAnApiDataExpiryBeforeTheRefreshTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000111',
            '01990d4a-0000-7000-8000-000000000101',
            Platform::YouTube,
            'UC0123456789',
            '@example',
            '@example',
            'Example Channel',
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-09-01 15:00:00+00:00'),
            new DateTimeImmutable('2026-09-01 15:00:00+00:00'),
            new DateTimeImmutable('2026-09-01 14:59:59+00:00'),
        );
    }

    private function account(?string $profileUrl = 'https://www.youtube.com/@example'): PlatformAccount
    {
        return new PlatformAccount(
            '01990d4a-0000-7000-8000-000000000111',
            '01990d4a-0000-7000-8000-000000000101',
            Platform::YouTube,
            'UC0123456789',
            '　@example　',
            '@example',
            'Example Channel',
            $profileUrl,
            'https://example.com/icon.png',
            null,
            true,
            new DateTimeImmutable('2026-09-02 00:00:00+09:00'),
        );
    }
}
