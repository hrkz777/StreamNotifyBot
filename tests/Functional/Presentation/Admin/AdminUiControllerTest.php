<?php

declare(strict_types=1);

namespace App\Tests\Functional\Presentation\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUiControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'dashboard' => ['/admin', 'おはようございます'];
        yield 'streamers' => ['/admin/streamers', '配信者'];
        yield 'notifications' => ['/admin/notifications', '通知設定'];
        yield 'platforms' => ['/admin/platforms', 'プラットフォーム'];
        yield 'settings' => ['/admin/settings', '運用設定'];
    }

    #[Test]
    #[DataProvider('pageProvider')]
    public function adminPageRendersMockUi(string $path, string $heading): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('cache-control', 'no-store, private');
        $contentSecurityPolicy = $client->getResponse()->headers->get('content-security-policy');
        self::assertNotNull($contentSecurityPolicy);
        self::assertMatchesRegularExpression(
            "/default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+';/",
            $contentSecurityPolicy,
        );
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertResponseHeaderSame('x-frame-options', 'DENY');
        self::assertSelectorTextContains('h1', $heading);
        self::assertSelectorTextContains('.preview-banner', '認証・データベース保存・外部APIにはまだ接続されていません');
        self::assertSelectorExists('script[nonce]');
        self::assertCount(5, $crawler->filter('.primary-nav a'));
    }

    #[Test]
    public function streamerPageIncludesInteractiveDialog(): void
    {
        $client = self::createClient();
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
    public function dashboardIncludesBrowserMockSummaryTargets(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-dashboard-streamer-count]', '0');
        self::assertSelectorTextSame('[data-dashboard-platform-summary]', '未登録');
    }

    #[Test]
    public function notificationPageIncludesEmptyInteractiveMockWithoutRealWebhookUrls(): void
    {
        $client = self::createClient();
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
        self::assertSelectorExists('[data-notification-form] input[name="webhook_video"]');
        self::assertSelectorExists('[data-notification-form] input[name="webhook_scheduled"]');
        self::assertSelectorExists('[data-notification-form] input[name="webhook_live"]');
        self::assertSelectorExists('[data-notification-form] input[name="webhook_ended"]');
        self::assertCount(0, $crawler->filter('[data-notification-list] .route-item'));
        self::assertSelectorNotExists('.avatar-group');
        self::assertSelectorNotExists('input[value*="discord.com/api/webhooks/"]');
    }

    #[Test]
    public function settingsPageIncludesPersistableValuesAndAbsoluteLimits(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-settings-form]');
        self::assertSelectorExists('[data-settings-reset]');
        self::assertSelectorExists('input[name="polling_scheduled_youtube"][value="900"][min="60"][max="604800"]');
        self::assertSelectorExists('input[name="polling_imminent_youtube"][value="60"]');
        self::assertSelectorExists('input[name="polling_error_twitcasting"]');
        self::assertSelectorExists('input[name="job_batch_size"][value="20"][min="1"][max="1000"]');
        self::assertSelectorExists('input[name="job_max_runtime"][value="45"][min="5"][max="900"]');
        self::assertSelectorExists('input[name="job_lease_seconds"][value="120"][max="3600"]');
        self::assertSelectorExists('input[name="quota_youtube_normal"][value="6000"]');
        self::assertSelectorExists('input[name="retention_delivery_results"][value="30"][min="7"][max="30"]');
        self::assertSelectorExists('input[name="retention_audit_logs"][value="365"][min="90"][max="3650"]');
    }

    #[Test]
    public function platformPageStartsWithoutInventedConnectionData(): void
    {
        $client = self::createClient();
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
}
