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
    public function notificationPageDoesNotExposeARealWebhookUrl(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.section-heading', '空欄にした通知種別は送信されません');
        self::assertSelectorNotExists('input[value*="discord.com/api/webhooks/"]');
    }
}
