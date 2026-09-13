<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Application\Catalog\StorePlatformApiCredential;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/platforms/credentials', name: 'admin_platform_api_credential_update', methods: ['POST'])]
final class PlatformApiCredentialUpdateController extends AbstractController
{
    public function __invoke(Request $request, StorePlatformApiCredential $storePlatformApiCredential, RequireAdministratorReauthentication $reauthentication): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('platform_api_credential_update', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $configuration = self::configuration($request);
            $storePlatformApiCredential->store($configuration);
            $this->addFlash('success', 'プラットフォーム接続情報を暗号化して更新しました。入力値は再表示されません。');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'プラットフォーム接続情報の入力値が不正です。');
        }

        return $this->redirectToRoute('admin_platforms');
    }

    private static function configuration(Request $request): PlatformApiCredentialConfiguration
    {
        $platform = $request->request->get('platform');
        if (!is_string($platform)) {
            throw new InvalidArgumentException('プラットフォームが不正です。');
        }
        $apiKey = $request->request->get('api_key');
        $webSubSecret = $request->request->get('websub_secret');
        $clientId = $request->request->get('client_id');
        $clientSecret = $request->request->get('client_secret');

        return match ($platform) {
            'youtube' => is_string($apiKey) && is_string($webSubSecret) ? PlatformApiCredentialConfiguration::youTube($apiKey, $webSubSecret) : throw new InvalidArgumentException('YouTube接続情報が不正です。'),
            'twitch' => is_string($clientId) && is_string($clientSecret) ? PlatformApiCredentialConfiguration::twitch($clientId, $clientSecret) : throw new InvalidArgumentException('Twitch接続情報が不正です。'),
            'twitcasting' => is_string($clientId) && is_string($clientSecret) ? PlatformApiCredentialConfiguration::twitCasting($clientId, $clientSecret) : throw new InvalidArgumentException('TwitCasting接続情報が不正です。'),
            default => throw new InvalidArgumentException('プラットフォームが不正です。'),
        };
    }
}
