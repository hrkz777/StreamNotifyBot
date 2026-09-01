<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\PlatformAccountResolver;
use App\Domain\Catalog\ResolvedPlatformAccount;
use App\Domain\Catalog\UnsupportedPlatform;
use InvalidArgumentException;

final readonly class PlatformAccountResolverRegistry implements PlatformAccountLookup
{
    /** @var array<string, PlatformAccountResolver> */
    private array $resolvers;

    /** @param iterable<PlatformAccountResolver> $resolvers */
    public function __construct(iterable $resolvers)
    {
        $indexedResolvers = [];
        foreach ($resolvers as $resolver) {
            $platformCode = $resolver->platform()->value;
            if (isset($indexedResolvers[$platformCode])) {
                throw new InvalidArgumentException(sprintf(
                    'プラットフォーム「%s」のResolverが重複しています。',
                    $resolver->platform()->displayId(),
                ));
            }

            $indexedResolvers[$platformCode] = $resolver;
        }

        $this->resolvers = $indexedResolvers;
    }

    public function resolve(Platform $platform, string $registrationIdentifier): ResolvedPlatformAccount
    {
        $resolver = $this->resolvers[$platform->value] ?? throw new UnsupportedPlatform($platform);

        return $resolver->resolve($registrationIdentifier);
    }
}
