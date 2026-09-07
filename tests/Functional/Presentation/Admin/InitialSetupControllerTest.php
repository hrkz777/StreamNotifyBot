<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InitialSetupControllerTest extends WebTestCase
{
    #[Test]
    public function initialSetupFormIsPublicAndIsNotCached(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/setup/YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '初期ownerを作成');
        self::assertSelectorExists('input[name="_csrf_token"]');
        $cacheControl = $client->getResponse()->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }
}
