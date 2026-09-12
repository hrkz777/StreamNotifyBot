<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use InvalidArgumentException;

final readonly class WebhookCallbackUrl
{
    public function __construct(public string $value)
    {
        $parts = parse_url($value);
        if (
            $value === ''
            || strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Webhook公開URLが不正です。');
        }
    }
}
