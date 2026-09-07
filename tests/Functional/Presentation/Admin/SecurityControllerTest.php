<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use App\Application\Administration\VerifyAdministratorTotp;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorTotpAlgorithm;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AdministratorTotpCredentialRepository;
use App\Domain\Security\EncryptedSecret;
use App\Domain\Security\SecretCipher;
use App\Domain\Security\SecretPurpose;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
    public function sessionCookieConfigurationUsesTheHostPrefixAndStrictSameSitePolicy(): void
    {
        $options = self::getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options);
        self::assertSame('StreamNotifyBot', $options['name'] ?? null);
        self::assertSame('/', $options['cookie_path'] ?? null);
        self::assertSame('auto', $options['cookie_secure'] ?? null);
        self::assertTrue($options['cookie_httponly'] ?? false);
        self::assertSame('strict', $options['cookie_samesite'] ?? null);
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
    public function validPasswordStartsTheTwoFactorChallenge(): void
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
        self::assertResponseRedirects('/admin/2fa');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertResponseHeaderSame('x-frame-options', 'DENY');
        $contentSecurityPolicy = $client->getResponse()->headers->get('content-security-policy');
        self::assertNotNull($contentSecurityPolicy);
        self::assertStringContainsString("frame-ancestors 'none'", $contentSecurityPolicy);
        self::assertSelectorTextContains('h1', '認証アプリの確認');
        self::assertSelectorExists('form[action="/admin/2fa/check"][method="post"]');
        self::assertSelectorExists('input[name="_auth_code"][autocomplete="one-time-code"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
        self::assertSelectorExists('form[action="/admin/logout"][method="post"]');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    #[Test]
    public function validTotpCompletesAuthenticationThroughTheReplayProtectedBoundary(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $administrator = $this->persistAdministrator();
        $this->configureSuccessfulTotpVerification($administrator->id);
        $crawler = $client->request('GET', '/admin/login');
        $client->submit($crawler->selectButton('ログイン')->form([
            'login_id' => $administrator->loginId,
            'password' => 'test-only-password',
        ]));
        $client->followRedirect();
        $crawler = $client->followRedirect();

        $client->submit($crawler->selectButton('確認')->form([
            '_auth_code' => '123456',
        ]));

        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-account strong', 'テスト管理者');
    }

    #[Test]
    public function invalidTotpKeepsTheAdministratorInTheTwoFactorChallenge(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $administrator = $this->persistAdministrator();
        $this->configureRejectedTotpVerification($administrator->id);
        $crawler = $this->beginTwoFactorChallenge($client, $administrator);

        $client->submit($crawler->selectButton('確認')->form([
            '_auth_code' => '000000',
        ]));

        self::assertResponseRedirects('/admin/2fa');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', '認証コードを確認できませんでした');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    #[Test]
    public function invalidTwoFactorCsrfTokenDoesNotCompleteAuthentication(): void
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $this->beginTwoFactorChallenge($client, $administrator);

        $client->request('POST', '/admin/2fa/check', [
            '_auth_code' => '123456',
            '_csrf_token' => 'invalid-two-factor-csrf-token',
        ]);

        self::assertResponseRedirects('/admin/2fa');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    #[Test]
    public function logoutFromTwoFactorChallengeCancelsThePartialAuthentication(): void
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $crawler = $this->beginTwoFactorChallenge($client, $administrator);
        $token = $crawler->filter('form[action="/admin/logout"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/admin/logout', ['_csrf_token' => $token]);

        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
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

    private function configureSuccessfulTotpVerification(string $administratorId): void
    {
        $credential = new AdministratorTotpCredential(
            $administratorId,
            new EncryptedSecret(str_repeat('e', 16), str_repeat('n', 24), 'test-key'),
            100,
        );
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::once())
            ->method('findByAdministratorId')
            ->with($administratorId)
            ->willReturn($credential);
        $repository->expects(self::once())
            ->method('acceptTimeStep')
            ->with($administratorId, 101)
            ->willReturn(true);
        $secretCipher = $this->createMock(SecretCipher::class);
        $secretCipher->expects(self::once())
            ->method('decrypt')
            ->with($credential->encryptedSecret, SecretPurpose::AdministratorTotpSecret, $administratorId)
            ->willReturn('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $algorithm = $this->createMock(AdministratorTotpAlgorithm::class);
        $algorithm->expects(self::once())
            ->method('matchTimeStep')
            ->with(
                'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
                '123456',
                self::isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(101);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-07 02:00:00+00:00'));
        self::getContainer()->set(
            VerifyAdministratorTotp::class,
            new VerifyAdministratorTotp($repository, $secretCipher, $algorithm, $clock),
        );
    }

    private function configureRejectedTotpVerification(string $administratorId): void
    {
        $repository = $this->createMock(AdministratorTotpCredentialRepository::class);
        $repository->expects(self::once())
            ->method('findByAdministratorId')
            ->with($administratorId)
            ->willReturn(null);
        self::getContainer()->set(
            VerifyAdministratorTotp::class,
            new VerifyAdministratorTotp(
                $repository,
                $this->createStub(SecretCipher::class),
                $this->createStub(AdministratorTotpAlgorithm::class),
                $this->createStub(Clock::class),
            ),
        );
    }

    private function beginTwoFactorChallenge(KernelBrowser $client, Administrator $administrator): Crawler
    {
        $crawler = $client->request('GET', '/admin/login');
        $client->submit($crawler->selectButton('ログイン')->form([
            'login_id' => $administrator->loginId,
            'password' => 'test-only-password',
        ]));
        $client->followRedirect();

        return $client->followRedirect();
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
