<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Administration\AdministratorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/administrators', name: 'admin_administrators', methods: ['GET'])]
final class AdministratorManagementController extends AbstractController
{
    public function __invoke(AdministratorRepository $administrators): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');

        $response = $this->render('admin/administrators.html.twig', [
            'administrators' => $administrators->findAll(),
            'preview_status' => '管理者アカウントの一覧と認証は接続済みです。操作機能は段階的に実装中です。',
            'content_security_policy_nonce' => $contentSecurityPolicyNonce = base64_encode(random_bytes(18)),
        ]);
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
