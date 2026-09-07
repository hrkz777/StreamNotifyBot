<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Security;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use App\Infrastructure\Security\AdministratorSecurityUser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdministratorSessionSubscriberTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $administratorId = null;

    #[Test]
    public function authenticatedRequestCreatesHashedAdministratorSessionMetadata(): void
    {
        [$client, $administrator] = $this->authenticatedClient();

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $rawSessionId = $this->sessionCookieValue($client);
        $row = $this->sessionRow($administrator->id);
        self::assertSame(hash('sha256', $rawSessionId), $row['token_hash']);
        self::assertNotSame($rawSessionId, $row['token_hash']);
        self::assertSame((string) $administrator->authenticationVersion, (string) $row['authentication_version']);
        self::assertNull($row['revoked_at']);
    }

    #[Test]
    public function revokedAdministratorSessionIsRejectedAndSymfonyAuthenticationIsCleared(): void
    {
        [$client, $administrator] = $this->authenticatedClient();
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $this->connection()->executeStatement(
            'UPDATE administrator_sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE administrator_id = ?',
            [Uuid::fromString($administrator->id)->toBinary()],
            [ParameterType::BINARY],
        );

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    #[Test]
    public function idleExpiredAdministratorSessionIsRejected(): void
    {
        [$client, $administrator] = $this->authenticatedClient();
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $this->connection()->executeStatement(
            <<<'SQL'
                UPDATE administrator_sessions
                SET idle_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND,
                    absolute_expires_at = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR
                WHERE administrator_id = ?
                SQL,
            [Uuid::fromString($administrator->id)->toBinary()],
            [ParameterType::BINARY],
        );

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    #[Test]
    public function validLogoutRevokesAdministratorSessionMetadata(): void
    {
        [$client, $administrator] = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrfToken = $crawler->filter('form[action="/admin/logout"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($csrfToken);

        $client->request('POST', '/admin/logout', ['_csrf_token' => $csrfToken]);

        self::assertResponseRedirects('/admin/login');
        $row = $this->sessionRow($administrator->id);
        self::assertNotNull($row['revoked_at']);
    }

    /** @return array{KernelBrowser, Administrator} */
    private function authenticatedClient(): array
    {
        $client = self::createClient();
        $administrator = $this->persistAdministrator();
        $client->loginUser(AdministratorSecurityUser::fromAdministrator($administrator));

        return [$client, $administrator];
    }

    private function persistAdministrator(): Administrator
    {
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $this->administratorId = Uuid::v7()->toRfc4122();
        $administrator = new Administrator(
            $this->administratorId,
            'session.web.'.substr($this->administratorId, -12),
            'セッション機能テスト管理者',
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
        (new DoctrineAdministratorRepository($this->connection()))->add($administrator);

        return $administrator;
    }

    /** @return array<string, mixed> */
    private function sessionRow(string $administratorId): array
    {
        $row = $this->connection()->fetchAssociative(
            <<<'SQL'
                SELECT
                    LOWER(HEX(token_hash)) AS token_hash,
                    authentication_version,
                    revoked_at
                FROM administrator_sessions
                WHERE administrator_id = ?
                ORDER BY created_at DESC
                LIMIT 1
                SQL,
            [Uuid::fromString($administratorId)->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function sessionCookieValue(KernelBrowser $client): string
    {
        $cookie = $client->getCookieJar()->get('STREAMNOTIFYBOTSESSID');
        self::assertNotNull($cookie);
        $value = $cookie->getValue();
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }

    private function connection(): Connection
    {
        if ($this->connection === null) {
            $connection = self::getContainer()->get(Connection::class);
            self::assertInstanceOf(Connection::class, $connection);
            $this->connection = $connection;
        }

        return $this->connection;
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
