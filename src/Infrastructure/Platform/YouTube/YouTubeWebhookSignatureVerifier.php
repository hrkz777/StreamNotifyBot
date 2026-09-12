<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Domain\Catalog\Platform;
use App\Domain\Security\SecretDecryptionFailed;
use InvalidArgumentException;

final readonly class YouTubeWebhookSignatureVerifier
{
    public function __construct(private PlatformApiCredentialConfigurationLoader $credentialLoader)
    {
    }

    public function isValid(string $payload, ?string $signature): bool
    {
        try {
            $credentials = $this->credentialLoader->load(Platform::YouTube);
        } catch (InvalidArgumentException|SecretDecryptionFailed) {
            return false;
        }
        $secret = $credentials?->value('websub_secret');
        if (!is_string($secret) || preg_match('/^[\x21-\x7E]{32,199}$/D', $secret) !== 1) {
            return false;
        }

        try {
            if ($signature === null || preg_match('/^sha1=[0-9a-f]{40}$/D', $signature) !== 1) {
                return false;
            }

            return hash_equals(
                sprintf('sha1=%s', hash_hmac('sha1', $payload, $secret)),
                $signature,
            );
        } finally {
            sodium_memzero($secret);
        }
    }
}
