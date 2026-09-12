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

        if ($path === '/admin/notifications') {
            self::assertResponseRedirects('/admin/notification-destinations');

            return;
        }

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
        } elseif ($path === '/admin') {
            self::assertSelectorTextContains('.preview-banner', 'Webhook購読状態はデータベースに接続済みです');
        } elseif ($path === '/admin/platforms') {
            self::assertSelectorTextContains('.preview-banner', '購読一覧はデータベースに接続済みです');
        } elseif ($path !== '/admin/streamers') {
            self::assertSelectorTextContains('.preview-banner', '認証は接続済み');
        }
        self::assertSelectorTextContains('.admin-account strong', 'テスト管理者');
        self::assertSelectorExists('form[action="/admin/logout"][method="post"] input[name="_csrf_token"]');
        self::assertSelectorExists('script[nonce]');
        self::assertCount(8, $crawler->filter('.primary-nav a'));
        self::assertSelectorExists('.primary-nav a[href="/admin/administrators/invitations"]');
        self::assertSelectorTextSame('.primary-nav a[href="/admin/streamers"] .nav-count', '0');
        self::assertSelectorTextSame('.primary-nav a[href="/admin/notifications"] .nav-count', '0');
    }

    #[Test]
    public function streamerPageRendersDatabaseBackedEmptyState(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin/streamers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview-banner', '一覧はデータベースに接続済みです');
        self::assertSelectorTextContains('.table-toolbar', '登録済みの配信者とアカウントを表示しています');
        self::assertSelectorNotExists('#streamer-dialog');
        self::assertSelectorNotExists('[data-streamer-list]');
        self::assertSelectorTextContains('.empty-table-row', '配信者はまだ登録されていません');
    }

    #[Test]
    public function dashboardIncludesPersistedCatalogSummary(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.preview-banner', 'Webhook購読状態はデータベースに接続済みです');
        self::assertSelectorTextSame('.metric-accent-purple strong', '0');
        self::assertSelectorTextContains('.metric-accent-purple small', '保存済みのライブ配信');
        self::assertSelectorTextSame('.metric-accent-blue strong', '0');
        self::assertSelectorTextContains('.metric-accent-blue small', '保存済みの予定配信');
        self::assertSelectorTextSame('.metric-accent-green strong', '0');
        self::assertSelectorTextContains('.metric-accent-green small', '送信済み通知');
        self::assertSelectorTextSame('.metric-accent-orange strong', '0');
        self::assertSelectorTextSame('.metric-accent-orange small', '未登録');
        self::assertSelectorExists('[data-dashboard-date]');
        self::assertSelectorExists('[data-dashboard-refresh]');
        self::assertSelectorTextContains('.live-panel .dashboard-empty-state', 'データベースに保存済みのライブ配信がある場合に表示します');
        self::assertSelectorTextContains('.schedule-panel .dashboard-empty-state', 'データベースに保存済みの今後の予定配信がある場合に表示します');
        self::assertSelectorTextContains('.activity-panel .dashboard-empty-state', 'データベースに保存済みの送信済み通知がある場合に表示します');
        self::assertSelectorTextSame('[data-dashboard-platform-status="youtube"] .health-warn', '未設定');
        self::assertStringNotContainsString('配信者名（未接続）', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function notificationPageRedirectsToThePersistedDestinationList(): void
    {
        $client = $this->authenticatedClient();
        $client->request('GET', '/admin/notifications');

        self::assertResponseRedirects('/admin/notification-destinations');
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
        self::assertSelectorExists('[data-job-policy="subscription_renewal"] input[name="batch_size"][value="20"][min="1"][max="1000"]');
        self::assertSelectorExists('[data-job-policy="cleanup"] input[name="batch_size"][value="100"][min="1"][max="1000"]');
        self::assertSelectorExists('[data-job-policy="subscription_renewal"] form[action="/admin/settings/job-policies/subscription_renewal"] input[name="_csrf_token"]');
        self::assertSelectorTextContains('[data-tab-panel="jobs"]', 'owner権限と再認証が必要です');
        self::assertSelectorExists('input[name="quota_youtube_normal"][value="6000"]');
        self::assertSelectorExists('input[name="retention_delivery_results"][value="30"][min="7"][max="30"]');
        self::assertSelectorExists('input[name="retention_audit_logs"][value="365"][min="90"][max="3650"]');
    }

    #[Test]
    public function reauthenticatedOwnerCanUpdateAJobPolicy(): void
    {
        $client = $this->authenticatedClient();
        $client->disableReboot();
        $connection = $this->connection;
        self::assertInstanceOf(Connection::class, $connection);
        $this->replaceReauthenticationGuard(true);
        $connection->beginTransaction();

        try {
            $crawler = $client->request('GET', '/admin/settings');
            $form = $crawler->filter('form[action="/admin/settings/job-policies/subscription_renewal"]')->form([
                'batch_size' => '21',
                'max_runtime_seconds' => '45',
                'max_attempts' => '8',
                'retry_initial_delay_seconds' => '60',
                'retry_max_delay_seconds' => '3600',
                'backoff_multiplier' => '2.0',
                'jitter_percent' => '20',
                'lease_seconds' => '120',
                'is_enabled' => '1',
            ]);
            $client->submit($form);

            self::assertResponseRedirects('/admin/settings');
            self::assertSame(21, self::integer($connection->fetchOne('SELECT batch_size FROM job_policies WHERE job_type = ?', ['subscription_renewal'])));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    #[Test]
    public function platformPageStartsWithoutInventedConnectionData(): void
    {
        $client = $this->authenticatedClient();
        $crawler = $client->request('GET', '/admin/platforms');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-platform-card]'));
        self::assertCount(3, $crawler->filter('.platform-stats > div:first-child dd'));
        self::assertSelectorTextSame('[data-platform-card="youtube"] .platform-stats > div:first-child dd', '0');
        self::assertSelectorTextSame('[data-platform-card="youtube"] .platform-stats > div:nth-child(2) dd', '0');
        self::assertSelectorTextSame('[data-platform-card="youtube"] [data-platform-state]', '未設定');
        self::assertSelectorTextSame('[data-platform-card="twitch"] [data-platform-state]', '未設定');
        self::assertSelectorTextSame('[data-platform-card="twitcasting"] [data-platform-state]', '未設定');
        self::assertSelectorTextContains('.empty-subscription-state', 'データベースに保存済みの有効なWebhook購読がある場合に表示します');
        self::assertSelectorExists('#platform-dialog');
        self::assertSelectorExists('[data-platform-form] input[name="quotaPercent"][min="0"][max="100"]');
        self::assertSelectorNotExists('[data-active-subscription]');
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
