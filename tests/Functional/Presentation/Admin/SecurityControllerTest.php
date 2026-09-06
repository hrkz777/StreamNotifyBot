<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Security\AdministratorSecurityUser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    #[Test]
    public function unauthenticatedAdminRequestRedirectsToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    #[Test]
    public function loginPageProvidesCsrfProtectedPasswordFormAndSecurityHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('cache-control', 'no-store, private');
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertResponseHeaderSame('x-frame-options', 'DENY');
        $contentSecurityPolicy = $client->getResponse()->headers->get('content-security-policy');
        self::assertNotNull($contentSecurityPolicy);
        self::assertStringContainsString("frame-ancestors 'none'", $contentSecurityPolicy);
        self::assertSelectorExists('form[action="/admin/login"][method="post"]');
        self::assertSelectorExists('input[name="login_id"][autocomplete="username"]');
        self::assertSelectorExists('input[name="password"][autocomplete="current-password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    #[Test]
    public function authenticatedUserIsRedirectedAwayFromLoginPage(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin/login');

        self::assertResponseRedirects('/admin');
    }

    #[Test]
    public function logoutRequiresPostAndValidCsrfToken(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/logout"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('GET', '/admin/logout');
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', '/admin/logout', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/admin/login');

        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $client->loginUser(AdministratorSecurityUser::fromAdministrator(new Administrator(
            '01990d4a-0000-7000-8000-000000000851',
            'test.owner',
            'テスト管理者',
            AdministratorRole::Owner,
            AdministratorStatus::Active,
            password_hash('test-only-password', PASSWORD_ARGON2ID),
            1,
            $now,
            $now,
            null,
            null,
            null,
            $now,
            $now,
            0,
        )));

        return $client;
    }
}
