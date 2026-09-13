<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Security\SecretDecryptionFailed;
use App\Infrastructure\Platform\Twitch\TwitchAccessTokenProvider;
use App\Infrastructure\Security\AdministratorSecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/platforms/twitch/connection-test', name: 'admin_twitch_connection_test', methods: ['POST'])]
final class TwitchCredentialConnectionTestController extends AbstractController
{
    public function __invoke(Request $request, TwitchAccessTokenProvider $tokenProvider, RequireAdministratorReauthentication $reauthentication): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('twitch_connection_test', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $token = $tokenProvider->accessToken();
            $tokenProvider->invalidate($token);
            sodium_memzero($token);
            $this->addFlash('success', 'Twitch接続情報を確認しました。');
        } catch (PlatformAccountResolutionFailed|SecretDecryptionFailed) {
            $this->addFlash('error', 'Twitch接続情報を確認できませんでした。入力値と外部サービスの状態を確認してください。');
        }

        return $this->redirectToRoute('admin_platforms');
    }
}
