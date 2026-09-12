<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Stream\PlatformVideoRepository;
use App\Domain\Stream\StreamNotificationOutboxRepository;
use App\Domain\Subscription\WebhookSubscriptionRepository;
use App\Domain\Subscription\WebhookSubscriptionStatus;
use App\Domain\Subscription\WebhookCallbackUrlRepository;
use App\Domain\System\Clock;
use App\Domain\System\OperationalSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminUiController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(StreamerCatalogRepository $streamerCatalogRepository, PlatformVideoRepository $platformVideoRepository, StreamNotificationOutboxRepository $streamNotificationOutboxRepository, WebhookSubscriptionRepository $webhookSubscriptionRepository, Clock $clock): Response
    {
        $streamers = $streamerCatalogRepository->findAllStreamers();
        $platformCounts = [];
        $platformAccounts = [];
        foreach ($streamers as $streamer) {
            foreach ($streamerCatalogRepository->findPlatformAccountsByStreamerId($streamer->id) as $account) {
                $platformCounts[$account->platform->displayId()] = ($platformCounts[$account->platform->displayId()] ?? 0) + 1;
                $platformAccounts[$account->id] = [
                    'platform' => $account->platform,
                    'streamer_name' => $streamer->nameFor(SupportedLanguage::Japanese)->name,
                ];
            }
        }
        $activeSubscriptionCounts = [];
        foreach ($webhookSubscriptionRepository->findByPlatformAccountIds(array_keys($platformAccounts)) as $subscription) {
            if ($subscription->status !== WebhookSubscriptionStatus::Active) {
                continue;
            }
            $platform = $platformAccounts[$subscription->platformAccountId]['platform'] ?? null;
            if ($platform !== null) {
                $activeSubscriptionCounts[$platform->value] = ($activeSubscriptionCounts[$platform->value] ?? 0) + 1;
            }
        }
        $liveStreams = [];
        foreach ($platformVideoRepository->findLiveByPlatformAccountIds(array_keys($platformAccounts)) as $video) {
            $account = $platformAccounts[$video->platformAccountId] ?? null;
            if ($account === null) {
                continue;
            }
            $liveStreams[] = [
                'platform' => $account['platform']->value,
                'platform_display_id' => $account['platform']->displayId(),
                'streamer_name' => $account['streamer_name'],
                'title' => $video->title,
                'actual_start_at' => $video->actualStartAt,
            ];
        }
        $upcomingStreams = [];
        foreach ($platformVideoRepository->findUpcomingByPlatformAccountIds(array_keys($platformAccounts), $clock->now(), 5) as $video) {
            $account = $platformAccounts[$video->platformAccountId] ?? null;
            if ($account === null || $video->scheduledStartAt === null) {
                continue;
            }
            $upcomingStreams[] = [
                'platform' => $account['platform']->value,
                'platform_display_id' => $account['platform']->displayId(),
                'streamer_name' => $account['streamer_name'],
                'title' => $video->title,
                'scheduled_start_at' => $video->scheduledStartAt,
            ];
        }
        $notificationActivity = [];
        $since = $clock->now()->modify('-24 hours');
        foreach ($streamNotificationOutboxRepository->findSentSince($since, 10) as $notification) {
            $video = $platformVideoRepository->findById($notification->platformVideoId);
            if ($video === null) {
                continue;
            }
            $notificationActivity[] = [
                'title' => $video->title,
                'type' => $notification->type->value,
                'sent_at' => $notification->sentAt,
            ];
        }

        return $this->adminResponse('admin/dashboard.html.twig', [
            'streamer_count' => count($streamers),
            'platform_summary' => $platformCounts === [] ? '未登録' : implode(' / ', array_map(static fn (string $platform, int $count): string => sprintf('%s %d', $platform, $count), array_keys($platformCounts), $platformCounts)),
            'live_streams' => $liveStreams,
            'upcoming_streams' => $upcomingStreams,
            'notification_activity' => $notificationActivity,
            'platform_active_subscription_counts' => $activeSubscriptionCounts,
            'preview_status' => '登録配信者数、プラットフォーム内訳、保存済みのライブ配信・予定配信・通知履歴・Webhook購読状態はデータベースに接続済みです。',
        ]);
    }

    #[Route('/streamers', name: 'streamers', methods: ['GET'])]
    public function streamers(StreamerCatalogRepository $streamerCatalogRepository, AgencyRepository $agencyRepository): Response
    {
        $streamers = [];
        foreach ($streamerCatalogRepository->findAllStreamers() as $streamer) {
            $agency = $agencyRepository->findById($streamer->agencyId);
            $accounts = $streamerCatalogRepository->findPlatformAccountsByStreamerId($streamer->id);
            $streamers[] = [
                'name' => $streamer->nameFor(SupportedLanguage::Japanese)->name,
                'agency_name' => $agency?->nameFor(SupportedLanguage::Japanese)->name ?? '所属区分（未接続）',
                'color_code' => $streamer->colorCode,
                'is_enabled' => $streamer->isEnabled,
                'accounts' => $accounts,
            ];
        }

        return $this->adminResponse('admin/streamers.html.twig', [
            'streamers' => $streamers,
            'agencies' => array_map(static fn ($agency): array => [
                'id' => $agency->id,
                'name' => $agency->nameFor(SupportedLanguage::Japanese)->name,
            ], $agencyRepository->findAll()),
            'preview_status' => '配信者とプラットフォームアカウントの一覧はデータベースに接続済みです。登録・編集は段階的に実装中です。',
        ]);
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(): Response
    {
        return $this->redirectToRoute('admin_notification_destinations');
    }

    #[Route('/platforms', name: 'platforms', methods: ['GET'])]
    public function platforms(StreamerCatalogRepository $streamerCatalogRepository, WebhookSubscriptionRepository $webhookSubscriptionRepository, PlatformApiCredentialRepository $platformApiCredentialRepository, WebhookCallbackUrlRepository $callbackUrlRepository): Response
    {
        $counts = [];
        $accountPlatforms = [];
        foreach ($streamerCatalogRepository->findAllStreamers() as $streamer) {
            foreach ($streamerCatalogRepository->findPlatformAccountsByStreamerId($streamer->id) as $account) {
                $counts[$account->platform->value] = ($counts[$account->platform->value] ?? 0) + 1;
                $accountPlatforms[$account->id] = $account->platform->value;
            }
        }
        $activeSubscriptionCounts = [];
        $activeSubscriptions = [];
        foreach ($webhookSubscriptionRepository->findByPlatformAccountIds(array_keys($accountPlatforms)) as $subscription) {
            if ($subscription->status !== WebhookSubscriptionStatus::Active) {
                continue;
            }
            $platform = $accountPlatforms[$subscription->platformAccountId] ?? null;
            if ($platform !== null) {
                $activeSubscriptionCounts[$platform] = ($activeSubscriptionCounts[$platform] ?? 0) + 1;
                $activeSubscriptions[] = [
                    'platform' => $platform,
                    'subscription_type' => $subscription->subscriptionType,
                    'expires_at' => $subscription->expiresAt,
                    'renew_after' => $subscription->renewAfter,
                ];
            }
        }
        $platformConnectionStates = [];
        $platformCredentialStates = [];
        foreach (['youtube', 'twitch', 'twitcasting'] as $platform) {
            $accountCount = $counts[$platform] ?? 0;
            $activeSubscriptionCount = $activeSubscriptionCounts[$platform] ?? 0;
            $hasCredentials = $platformApiCredentialRepository->findByPlatform(Platform::from($platform)) !== null;
            $platformConnectionStates[$platform] = match (true) {
                $activeSubscriptionCount > 0 => ['label' => '購読有効', 'class' => 'is-ok'],
                $accountCount > 0 => ['label' => '購読未確認', 'class' => 'is-warning'],
                default => ['label' => '未設定', 'class' => 'is-warning'],
            };
            $platformCredentialStates[$platform] = [
                'label' => $hasCredentials ? '接続情報設定済み' : '接続情報未設定',
                'class' => $hasCredentials ? 'is-ok' : 'is-warning',
            ];
        }

        return $this->adminResponse('admin/platforms.html.twig', [
            'platform_account_counts' => $counts,
            'platform_active_subscription_counts' => $activeSubscriptionCounts,
            'active_subscriptions' => $activeSubscriptions,
            'platform_connection_states' => $platformConnectionStates,
            'platform_credential_states' => $platformCredentialStates,
            'webhook_callback_url' => $callbackUrlRepository->find()?->value,
            'preview_status' => 'プラットフォームごとの登録アカウント数、有効なWebhook購読数、購読一覧、接続状態はデータベースに接続済みです。API使用量の計測は段階的に実装中です。',
        ]);
    }

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(JobPolicyRepository $jobPolicyRepository, OperationalSettingRepository $operationalSettingRepository): Response
    {
        $operationalSettings = [];
        foreach ($operationalSettingRepository->findAll() as $setting) {
            $operationalSettings[$setting->key] = $setting->value;
        }

        return $this->adminResponse('admin/settings.html.twig', [
            'job_policies' => $jobPolicyRepository->findAll(),
            'operational_settings' => $operationalSettings,
            'preview_status' => '認証、Cronジョブ、ポーリング、API予算、保持期間の表示と編集はデータベースに接続済みです。',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function adminResponse(string $template, array $parameters = []): Response
    {
        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $parameters['content_security_policy_nonce'] = $contentSecurityPolicyNonce;
        $parameters['preview_status'] ??= '表示データと操作結果は一部モックです。認証は接続済みで、データベース保存・外部API接続は段階的に実装中です。';
        $response = $this->render($template, $parameters);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'nonce-{$contentSecurityPolicyNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
