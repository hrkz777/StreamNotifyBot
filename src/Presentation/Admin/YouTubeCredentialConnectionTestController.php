<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\PlatformAccountLookup;
use App\Domain\Catalog\Platform;
use App\Domain\Security\SecretDecryptionFailed;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/platforms/youtube/connection-test', name: 'admin_youtube_connection_test', methods: ['POST'])]
final class YouTubeCredentialConnectionTestController extends AbstractController
{
    public function __invoke(Request $request, PlatformAccountLookup $resolver, RequireAdministratorReauthentication $reauthentication): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('youtube_connection_test', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $identifier = $request->request->get('channel_identifier');
            if (!is_string($identifier)) {
                throw new InvalidArgumentException('YouTubeチャンネル識別子が不正です。');
            }
            $resolver->resolve(Platform::YouTube, $identifier);
            $this->addFlash('success', 'YouTube接続情報を確認しました。');
        } catch (InvalidArgumentException|PlatformAccountNotFound|PlatformAccountResolutionFailed|SecretDecryptionFailed) {
            $this->addFlash('error', 'YouTube接続情報を確認できませんでした。APIキーとチャンネル識別子を確認してください。');
        }

        return $this->redirectToRoute('admin_platforms');
    }
}
