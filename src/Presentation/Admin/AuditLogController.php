<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Administration\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/audit-logs', name: 'admin_audit_logs', methods: ['GET'])]
final class AuditLogController extends AbstractController
{
    public function __invoke(AuditLogRepository $auditLogs): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $response = $this->render('admin/audit_logs.html.twig', [
            'audit_logs' => $auditLogs->findLatest(100),
            'preview_status' => '監査ログと認証は接続済みです。最新100件をデータベースから表示しています。',
            'content_security_policy_nonce' => $contentSecurityPolicyNonce,
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
