<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SecurityControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $administratorId = null;

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
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
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
    public function validPasswordCompletesTheFirstFactorWhileTotpIsNotYetConnected(): void
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $crawler = $client->request('GET', '/admin/login');

        $client->submit($crawler->selectButton('ログイン')->form([
            'login_id' => $administrator->loginId,
            'password' => 'test-only-password',
        ]));

        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-account strong', 'テスト管理者');
    }

    #[Test]
    public function invalidPasswordShowsOnlyTheGenericAuthenticationFailure(): void
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $crawler = $client->request('GET', '/admin/login');

        $client->submit($crawler->selectButton('ログイン')->form([
            'login_id' => $administrator->loginId,
            'password' => 'incorrect-test-password',
        ]));

        self::assertResponseRedirects('/admin/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'ログインIDまたはパスワードを確認できませんでした');
        self::assertStringNotContainsString('incorrect-test-password', (string) $client->getResponse()->getContent());
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    #[Test]
    public function invalidLoginCsrfTokenDoesNotAuthenticate(): void
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();

        $client->request('POST', '/admin/login', [
            'login_id' => $administrator->loginId,
            'password' => 'test-only-password',
            '_csrf_token' => 'invalid-login-csrf-token',
        ]);

        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
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

    #[Test]
    public function invalidLogoutCsrfTokenDoesNotEndTheAuthenticatedSession(): void
    {
        $client = $this->authenticatedClient();

        $client->request('POST', '/admin/logout', ['_csrf_token' => 'invalid-logout-csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $client->loginUser(AdministratorSecurityUser::fromAdministrator($administrator));

        return $client;
    }

    private function persistAdministrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $this->administratorId = Uuid::v7()->toRfc4122();
        $administrator = new Administrator(
            $this->administratorId,
            'test.owner.'.substr($this->administratorId, -12),
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
        );
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        (new DoctrineAdministratorRepository($connection))->add($administrator);

        return $administrator;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null && $this->administratorId !== null) {
                $this->connection->executeStatement(
                    'DELETE FROM administrators WHERE id = ?',
                    [Uuid::fromString($this->administratorId)->toBinary()],
                    [ParameterType::BINARY],
                );
            }
        } finally {
            parent::tearDown();
        }
    }
}
