<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdministratorInvitationAcceptanceControllerTest extends WebTestCase
{
    #[Test]
    public function invitationAcceptanceFormIsPublicAndIsNotCached(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/invitations/YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '管理者招待を受諾');
        self::assertSelectorExists('form[action="/admin/invitations/YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE"][method="post"]');
        self::assertSelectorExists('input[name="password"][autocomplete="new-password"]');
        self::assertSelectorExists('input[name="totp_code"][autocomplete="one-time-code"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
    }
}
