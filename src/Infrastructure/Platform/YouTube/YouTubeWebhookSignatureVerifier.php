<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\YouTube;

use InvalidArgumentException;

final readonly class YouTubeWebhookSignatureVerifier
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        if (preg_match('/^[\x21-\x7E]{32,199}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('YouTube WebSubの署名検証用秘密値が不正です。');
        }
    }

    public function isValid(string $payload, ?string $signature): bool
    {
        if ($signature === null || preg_match('/^sha1=[0-9a-f]{40}$/D', $signature) !== 1) {
            return false;
        }

        return hash_equals(
            sprintf('sha1=%s', hash_hmac('sha1', $payload, $this->secret)),
            $signature,
        );
    }
}
