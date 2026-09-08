<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AdministratorToken;
use App\Domain\Administration\AdministratorTokenPurpose;
use App\Infrastructure\Persistence\DoctrineAdministratorInvitationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

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

    #[Test]
    public function itConsumesTheInvitationAndDisplaysRecoveryCodesAfterSuccessfulPost(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        $administratorId = '01990d4a-0000-7000-8000-000000000420';
        $token = 'test-invitation-token';
        $now = new DateTimeImmutable('now');
        try {
            (new DoctrineAdministratorInvitationRepository($connection))->create(
                new Administrator($administratorId, 'invited.admin', '招待管理者', AdministratorRole::Administrator, AdministratorStatus::Pending, null, 1, null, null, null, null, null, $now, $now, 0),
                new AdministratorToken('01990d4a-0000-7000-8000-000000000421', $administratorId, AdministratorTokenPurpose::Invitation, hash('sha256', $token), null, 1, $now, $now->modify('+30 minutes'), null, null),
            );
            $crawler = $client->request('GET', '/admin/invitations/'.$token);
            parse_str((string) parse_url(trim($crawler->filter('code')->first()->text()), PHP_URL_QUERY), $parameters);
            self::assertIsString($parameters['secret'] ?? null);
            $clock = self::getContainer()->get(ClockInterface::class);
            self::assertInstanceOf(ClockInterface::class, $clock);
            $totpCode = TOTP::create($parameters['secret'], 30, 'sha1', 6, 0, $clock)->now();

            $client->submit($crawler->selectButton('招待を受諾')->form(['password' => 'a unique passphrase!', 'totp_code' => $totpCode]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', '管理者登録が完了しました');
            self::assertSelectorCount(10, 'li code');
            self::assertSame('active', $connection->fetchOne('SELECT status FROM administrators WHERE id = ?', [Uuid::fromString($administratorId)->toBinary()], [ParameterType::BINARY]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
