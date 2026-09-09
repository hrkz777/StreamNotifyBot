<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Http;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class YouTubeWebhookControllerTest extends WebTestCase
{
    #[Test]
    public function itDoesNotExposeWhetherAnInvalidSubscriptionExists(): void
    {
        $client = self::createClient();
        $client->request(
            'GET',
            '/webhooks/youtube/01990d4a-0000-7000-8000-000000000401?hub.mode=unsubscribe&hub.topic=x&hub.challenge=x',
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame('', $client->getResponse()->getContent());
    }

    #[Test]
    public function itRejectsAnUnsignedNotificationPost(): void
    {
        $client = self::createClient();
        $client->request('POST', '/webhooks/youtube/01990d4a-0000-7000-8000-000000000401');

        self::assertResponseStatusCodeSame(404);
    }
}
