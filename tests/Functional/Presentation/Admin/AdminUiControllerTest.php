<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Administration\Administrator;
use App\Domain\Administration\AdministratorRole;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AdministratorStatus;
use App\Domain\Administration\AuthenticationPolicy;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorSecurityUser;
use App\Infrastructure\Persistence\DoctrineAdministratorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminUiControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $administratorId = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'dashboard' => ['/admin', 'ダッシュボード'];
        yield 'streamers' => ['/admin/streamers', '配信者'];
        yield 'agencies' => ['/admin/agencies', '所属区分'];
        yield 'notifications' => ['/admin/notifications', '通知設定'];
        yield 'platforms' => ['/admin/platforms', 'プラットフォーム'];
        yield 'administrators' => ['/admin/administrators', '管理者管理'];
        yield 'audit logs' => ['/admin/audit-logs', '監査ログ'];
        yield 'settings' => ['/admin/settings', '運用設定'];
    }

    #[Test]
    #[DataProvider('pageProvider')]
    public function adminPageRendersMockUi(string $path, string $heading): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $contentSecurityPolicy = $client->getResponse()->headers->get('content-security-policy');
        self::assertNotNull($contentSecurityPolicy);
        self::assertMatchesRegularExpression(
            "/default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+';/",
            $contentSecurityPolicy,
        );
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertResponseHeaderSame('x-frame-options', 'DENY');
        self::assertSelectorTextContains('h1', $heading);
        if ($path === '/admin/settings') {
            self::assertSelectorTextContains('.preview-banner', '認証とCronジョブ設定の表示はデータベースに接続済みです');
        } elseif ($path === '/admin/agencies') {
            self::assertSelectorTextContains('.preview-banner', '所属区分はデータベースへ保存されます');
        } else {
            self::assertSelectorTextContains('.preview-banner', '認証は接続済み');
        }
        self::assertSelectorTextContains('.admin-account strong', 'テスト管理者');
        self::assertSelectorExists('form[action="/admin/logout"][method="post"] input[name="_csrf_token"]');
        self::assertSelectorExists('script[nonce]');
        self::assertSelectorExists('head > link[rel="stylesheet"][href$=".css"]');
        self::assertNotFalse($client->getResponse()->getContent());
        self::assertStringNotContainsString('data:application/javascript,', $client->getResponse()->getContent());
        self::assertCount(9, $crawler->filter('.primary-nav a'));
        self::assertSelectorExists('.primary-nav a[href="/admin/administrators/invitations"]');
    }

    #[Test]
    public function streamerPageIncludesInteractiveDialog(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin/streamers');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#streamer-dialog');
        self::assertSelectorExists('[data-dialog-open="streamer-dialog"]');
        self::assertSelectorExists('[data-streamer-form] input[name="nameJa"][maxlength="100"]');
        self::assertSelectorExists('[data-streamer-form] input[name="identifier"][maxlength="255"]');
        self::assertSelectorExists('[data-streamer-agency-filter]');
        self::assertSelectorExists('[data-streamer-state-filter]');
        self::assertSelectorExists('[data-streamer-clear]');
        self::assertSelectorTextContains('.empty-table-row', '配信者はまだ登録されていません');
    }

    #[Test]
    public function administratorCanCreateAnAgencyThatIsShownFromTheDatabase(): void
    {
        $client = $this->authenticatedClient();
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $code = 'functional_agency';

        try {
            $crawler = $client->request('GET', '/admin/agencies');
            $form = $crawler->filter('form[action="/admin/agencies"]')->form([
                'code' => $code,
                'default_language' => 'ja',
                'name_ja' => '機能テスト所属',
                'short_name_ja' => '機能テスト',
                'is_independent' => '1',
            ]);
            $client->submit($form);

            self::assertResponseRedirects('/admin/agencies');
            $client->followRedirect();
            self::assertSelectorTextContains('main .preview-banner', '所属区分を登録しました。');
            self::assertSelectorTextContains('tbody tr', '機能テスト');
            self::assertSame(1, self::integer($connection->fetchOne('SELECT COUNT(*) FROM agencies WHERE code = ?', [$code])));
        } finally {
            $connection->executeStatement('DELETE FROM agency_names WHERE agency_id IN (SELECT id FROM agencies WHERE code = ?)', [$code]);
            $connection->executeStatement('DELETE FROM agencies WHERE code = ?', [$code]);
        }
    }

    #[Test]
    public function agencyCreationRejectsAnInvalidCsrfToken(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/admin/agencies', [
            '_csrf_token' => 'invalid-agency-creation-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function dashboardIncludesBrowserMockSummaryTargets(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-dashboard-streamer-count]', '0');
        self::assertSelectorTextSame('[data-dashboard-platform-summary]', '未登録');
        self::assertSelectorExists('[data-dashboard-date]');
        self::assertSelectorExists('[data-dashboard-refresh]');
        self::assertSelectorTextContains('.live-panel .dashboard-empty-state', '配信情報はまだありません');
        self::assertSelectorTextContains('.schedule-panel .dashboard-empty-state', '配信予定はまだありません');
        self::assertSelectorTextContains('.activity-panel .dashboard-empty-state', '通知履歴はまだありません');
        self::assertStringNotContainsString('配信者名（未接続）', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function notificationPageIncludesEmptyInteractiveMockWithoutRealWebhookUrls(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.section-heading', '空欄にした通知種別は送信されません');
        self::assertSelectorTextSame('[data-notification-count]', '0');
        self::assertSelectorTextSame('[data-notification-destination-count]', '0');
        self::assertSelectorExists('#notification-dialog');
        self::assertSelectorExists('#notification-streamers-dialog');
        self::assertSelectorExists('[data-notification-create-form] input[name="name"][maxlength="100"]');
        self::assertSelectorExists('[data-notification-streamers-form]');
        self::assertSelectorExists('[data-notification-streamer-list]');
        self::assertCount(4, $crawler->filter('[data-webhook-input-list]'));
        self::assertCount(4, $crawler->filter('[data-webhook-add]'));
        self::assertSelectorExists('[data-webhook-input-list="video"]');
        self::assertSelectorExists('[data-webhook-input-list="scheduled"]');
        self::assertSelectorExists('[data-webhook-input-list="live"]');
        self::assertSelectorExists('[data-webhook-input-list="ended"]');
        self::assertCount(0, $crawler->filter('[data-notification-list] .route-item'));
        self::assertSelectorNotExists('.avatar-group');
        self::assertSelectorNotExists('input[value*="discord.com/api/webhooks/"]');
    }

    #[Test]
    public function settingsPageIncludesDatabasePoliciesAndMockValues(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-settings-form]');
        self::assertSelectorExists('[data-settings-reset]');
        self::assertSelectorExists('input[name="polling_scheduled_youtube"][value="900"][min="60"][max="604800"]');
        self::assertSelectorExists('input[name="polling_imminent_youtube"][value="60"]');
        self::assertSelectorExists('input[name="polling_error_twitcasting"]');
        self::assertCount(5, $client->getCrawler()->filter('[data-job-policy]'));
        self::assertSelectorExists('[data-job-policy="subscription_renewal"][open]');
        self::assertSelectorTextContains('[data-job-policy="subscription_renewal"] summary', 'Webhook購読更新');
        self::assertSelectorExists('[data-job-policy="subscription_renewal"] input[data-server-setting][value="20"][disabled]');
        self::assertSelectorExists('[data-job-policy="cleanup"] input[data-server-setting][value="100"][disabled]');
        self::assertSelectorTextContains('[data-tab-panel="jobs"]', '現在は参照のみです');
        self::assertSelectorExists('input[name="quota_youtube_normal"][value="6000"]');
        self::assertSelectorExists('input[name="retention_delivery_results"][value="30"][min="7"][max="30"]');
        self::assertSelectorExists('input[name="retention_audit_logs"][value="365"][min="90"][max="3650"]');
    }

    #[Test]
    public function platformPageStartsWithoutInventedConnectionData(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin/platforms');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-platform-card]'));
        self::assertCount(3, $crawler->filter('[data-platform-account-count]'));
        self::assertSelectorTextSame('[data-platform-card="youtube"] [data-platform-state]', '未設定');
        self::assertSelectorTextSame('[data-platform-card="twitch"] [data-platform-state]', '未設定');
        self::assertSelectorTextSame('[data-platform-card="twitcasting"] [data-platform-state]', '未設定');
        self::assertSelectorTextContains('.empty-subscription-state', '有効な購読はありません');
        self::assertSelectorExists('#platform-dialog');
        self::assertSelectorExists('[data-platform-form] input[name="quotaPercent"][min="0"][max="100"]');
        self::assertSelectorNotExists('.subscription-panel tbody tr');
        self::assertStringNotContainsString('最終同期 2分前', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function administratorPageProvidesCsrfProtectedActionsOnlyForOtherAdministrators(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin/administrators');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/deactivate"]'));
        self::assertCount(0, $crawler->filter('form[action*="/delete"]'));
    }

    #[Test]
    public function administratorActionsRejectAnInvalidCsrfTokenBeforeChangingAnyState(): void
    {
        $client = $this->authenticatedClient();
        $client->request('POST', '/admin/administrators/01990d4a-0000-7000-8000-000000000599/deactivate', [
            '_csrf_token' => 'invalid-administrator-management-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function reauthenticatedOwnerCanDeactivateAnotherAdministrator(): void
    {
        $client = $this->authenticatedClient();
        $client->disableReboot();
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $targetId = Uuid::v7()->toRfc4122();
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        (new DoctrineAdministratorRepository($connection))->add(new Administrator(
            $targetId,
            'target.admin.'.substr($targetId, -12),
            '対象管理者',
            AdministratorRole::Administrator,
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
        ));
        $this->replaceReauthenticationGuard(true);

        try {
            $crawler = $client->request('GET', '/admin/administrators');
            $form = $crawler->filter(sprintf('form[action="/admin/administrators/%s/deactivate"]', $targetId))->form();
            $client->submit($form);

            self::assertResponseRedirects('/admin/administrators');
            self::assertSame(
                'disabled',
                $connection->fetchOne(
                    'SELECT status FROM administrators WHERE id = ?',
                    [Uuid::fromString($targetId)->toBinary()],
                    [ParameterType::BINARY],
                ),
            );
            $auditLog = $connection->fetchAssociative(
                'SELECT action_code, actor_administrator_id, target_id, result FROM audit_logs WHERE target_id = ?',
                [$targetId],
                [ParameterType::STRING],
            );
            self::assertIsArray($auditLog);
            self::assertSame('administrator.deactivated', $auditLog['action_code']);
            self::assertIsString($auditLog['actor_administrator_id']);
            self::assertSame($this->administratorId, Uuid::fromBinary($auditLog['actor_administrator_id'])->toRfc4122());
            self::assertSame($targetId, $auditLog['target_id']);
            self::assertSame('succeeded', $auditLog['result']);
        } finally {
            $connection->executeStatement(
                'DELETE FROM administrators WHERE id = ?',
                [Uuid::fromString($targetId)->toBinary()],
                [ParameterType::BINARY],
            );
        }
    }

    #[Test]
    public function reauthenticatedOwnerCanLogicallyDeleteAnotherAdministrator(): void
    {
        $client = $this->authenticatedClient();
        $client->disableReboot();
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $targetId = Uuid::v7()->toRfc4122();
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        (new DoctrineAdministratorRepository($connection))->add(new Administrator(
            $targetId,
            'delete.target.'.substr($targetId, -12),
            '削除対象管理者',
            AdministratorRole::Administrator,
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
        ));
        $this->replaceReauthenticationGuard(true);

        try {
            $crawler = $client->request('GET', '/admin/administrators');
            $form = $crawler->filter(sprintf('form[action="/admin/administrators/%s/delete"]', $targetId))->form();
            $client->submit($form);

            self::assertResponseRedirects('/admin/administrators');
            $row = $connection->fetchAssociative(
                'SELECT status, password_hash, deleted_at, authentication_version FROM administrators WHERE id = ?',
                [Uuid::fromString($targetId)->toBinary()],
                [ParameterType::BINARY],
            );
            self::assertIsArray($row);
            self::assertSame('deleted', $row['status']);
            self::assertNull($row['password_hash']);
            self::assertNotNull($row['deleted_at']);
            self::assertSame(2, self::integer($row['authentication_version']));
        } finally {
            $connection->executeStatement(
                'DELETE FROM administrators WHERE id = ?',
                [Uuid::fromString($targetId)->toBinary()],
                [ParameterType::BINARY],
            );
        }
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $now = new DateTimeImmutable('2026-09-07 00:00:00+00:00');
        $this->administratorId = Uuid::v7()->toRfc4122();
        $administrator = new Administrator(
            $this->administratorId,
            'test.owner.'.substr($this->administratorId, -12),
            'テスト管理者',
            AdministratorRole::Owner,
            AdministratorStatus::Active,
            '$argon2id$test-fixture-not-used-for-password-verification',
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
        $client->loginUser(AdministratorSecurityUser::fromAdministrator($administrator));

        return $client;
    }

    private function replaceReauthenticationGuard(bool $satisfied): void
    {
        $sessions = $this->createStub(AdministratorSessionRepository::class);
        $sessions->method('isReauthenticatedSince')->willReturn($satisfied);
        $now = new DateTimeImmutable('2026-09-08 00:00:00+00:00');
        $policy = new AuthenticationPolicy(AuthenticationPolicy::ID, 30, 12, 10, 15, 5, 15, null, $now, 0);
        $policies = $this->createStub(AuthenticationPolicyRepository::class);
        $policies->method('get')->willReturn($policy);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        self::getContainer()->set(
            RequireAdministratorReauthentication::class,
            new RequireAdministratorReauthentication(
                $sessions,
                $policies,
                $clock,
            ),
        );
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        self::fail('DBから整数を取得できませんでした。');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null && $this->administratorId !== null) {
                $this->connection->executeStatement(
                    'DELETE FROM audit_logs WHERE actor_administrator_id = ?',
                    [Uuid::fromString($this->administratorId)->toBinary()],
                    [ParameterType::BINARY],
                );
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
