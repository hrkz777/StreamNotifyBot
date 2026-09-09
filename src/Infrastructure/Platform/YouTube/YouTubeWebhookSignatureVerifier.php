<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

final readonly class YouTubeWebhookSignatureVerifier
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
    }

    public function isValid(string $payload, ?string $signature): bool
    {
        if (
            preg_match('/^[\x21-\x7E]{32,199}$/D', $this->secret) !== 1
            || $signature === null
            || preg_match('/^sha1=[0-9a-f]{40}$/D', $signature) !== 1
        ) {
            return false;
        }

        return hash_equals(
            sprintf('sha1=%s', hash_hmac('sha1', $payload, $this->secret)),
            $signature,
        );
    }
}
