<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Notification;

use App\Infrastructure\Notification\DiscordWebhookNotifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscordWebhookNotifierTest extends TestCase
{
    #[Test]
    public function itPostsJsonToAnHttpsDiscordWebhookWithoutRedirects(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://discord.com/api/webhooks/123456789012345678/token_value', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertIsString($options['body']);
            self::assertSame(['content' => '通知'], json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR));

            return new MockResponse('', ['http_code' => 204]);
        });

        (new DiscordWebhookNotifier($client))->send('https://discord.com/api/webhooks/123456789012345678/token_value', ['content' => '通知']);
    }
}
