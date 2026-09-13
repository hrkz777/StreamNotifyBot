<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

interface WebhookCallbackUrlRepository
{
    public function find(): ?WebhookCallbackUrl;

    public function save(WebhookCallbackUrl $url): void;
}
