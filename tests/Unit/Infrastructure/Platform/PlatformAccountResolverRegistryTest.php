<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountResolver;
use App\Domain\Catalog\ResolvedPlatformAccount;
use App\Domain\Catalog\UnsupportedPlatform;
use App\Infrastructure\Platform\PlatformAccountResolverRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformAccountResolverRegistryTest extends TestCase
{
    #[Test]
    public function itDelegatesToTheResolverForTheRequestedPlatform(): void
    {
        $resolvedAccount = new ResolvedPlatformAccount('UC_TEST', null, null, null, null, null, null);
        $resolver = $this->createMock(PlatformAccountResolver::class);
        $resolver->method('platform')->willReturn(Platform::YouTube);
        $resolver->expects($this->once())->method('resolve')->with('@test')->willReturn($resolvedAccount);

        self::assertSame(
            $resolvedAccount,
            (new PlatformAccountResolverRegistry([$resolver]))->resolve(Platform::YouTube, '@test'),
        );
    }

    #[Test]
    public function itRejectsDuplicateResolvers(): void
    {
        $first = $this->createStub(PlatformAccountResolver::class);
        $first->method('platform')->willReturn(Platform::YouTube);
        $second = $this->createStub(PlatformAccountResolver::class);
        $second->method('platform')->willReturn(Platform::YouTube);

        $this->expectException(InvalidArgumentException::class);

        new PlatformAccountResolverRegistry([$first, $second]);
    }

    #[Test]
    public function itRejectsAPlatformWithoutAResolver(): void
    {
        $this->expectException(UnsupportedPlatform::class);

        (new PlatformAccountResolverRegistry([]))->resolve(Platform::Twitch, 'test');
    }
}
