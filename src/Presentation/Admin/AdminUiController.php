<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Job\JobPolicyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminUiController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->adminResponse('admin/dashboard.html.twig');
    }

    #[Route('/streamers', name: 'streamers', methods: ['GET'])]
    public function streamers(): Response
    {
        return $this->adminResponse('admin/streamers.html.twig');
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(): Response
    {
        return $this->adminResponse('admin/notifications.html.twig');
    }

    #[Route('/platforms', name: 'platforms', methods: ['GET'])]
    public function platforms(): Response
    {
        return $this->adminResponse('admin/platforms.html.twig');
    }

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(JobPolicyRepository $jobPolicyRepository): Response
    {
        return $this->adminResponse('admin/settings.html.twig', [
            'job_policies' => $jobPolicyRepository->findAll(),
            'preview_status' => 'Cronジョブ設定の表示はデータベースに接続済みです。編集機能とその他の設定はまだモックです。',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function adminResponse(string $template, array $parameters = []): Response
    {
        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $parameters['content_security_policy_nonce'] = $contentSecurityPolicyNonce;
        $parameters['preview_status'] ??= '表示データと操作結果はモックです。認証・データベース保存・外部APIにはまだ接続されていません。';
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
