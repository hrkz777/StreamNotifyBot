<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Catalog\SavePlatformApiCredential;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformApiCredentialRepository;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Notification\NotificationRouteRepository;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminUiController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->adminResponse('admin/dashboard.html.twig');
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(NotificationRouteRepository $routes, StreamerCatalogRepository $streamers): Response
    {
        return $this->adminResponse('admin/notifications.html.twig', [
            'routes' => $routes->findAll(),
            'streamers' => $streamers->findAllStreamers(),
        ]);
    }

    #[Route('/platforms', name: 'platforms', methods: ['GET'])]
    public function platforms(PlatformApiCredentialRepository $credentialRepository): Response
    {
        $configuredPlatforms = [];
        foreach (Platform::cases() as $platform) {
            $configuredPlatforms[$platform->value] = $credentialRepository->findByPlatform($platform) !== null;
        }

        return $this->adminResponse('admin/platforms.html.twig', ['configured_platforms' => $configuredPlatforms, 'preview_status' => 'API資格情報は暗号化してデータベースへ保存されます。保存済みの値は再表示しません。']);
    }

    #[Route('/platforms/{platform}', name: 'platform_credential_save', methods: ['POST'])]
    public function savePlatformCredential(string $platform, Request $request, SavePlatformApiCredential $savePlatformApiCredential): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('platform_credential_'.$platform, $request->request->getString('_token'))) {
            $this->addFlash('error', 'フォームの有効期限が切れました。画面を再読み込みしてからもう一度実行してください。');

            return $this->redirectToRoute('admin_platforms');
        }
        try {
            $platformType = Platform::from($platform);
            $savePlatformApiCredential->save($platformType, $request->request->all());
            $this->addFlash('success', sprintf('%sのAPI資格情報を保存しました。保存済みの値は表示されません。', $platformType->displayId()));
        } catch (\ValueError|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_platforms');
    }

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(JobPolicyRepository $jobPolicyRepository): Response
    {
        return $this->adminResponse('admin/settings.html.twig', [
            'job_policies' => $jobPolicyRepository->findAll(),
            'preview_status' => '認証とCronジョブ設定の表示はデータベースに接続済みです。編集機能とその他の設定はまだモックです。',
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
