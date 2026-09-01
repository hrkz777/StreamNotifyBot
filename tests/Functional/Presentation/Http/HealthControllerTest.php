<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Http;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    #[Test]
    public function healthEndpointReturnsAHealthyResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $content = $client->getResponse()->getContent();
        self::assertNotFalse($content);
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            $content,
        );
    }
}
