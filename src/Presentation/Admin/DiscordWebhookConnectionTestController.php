<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Application\Stream\SendDiscordNotification;
use App\Domain\Stream\NotificationDestination;
use App\Domain\Stream\NotificationDestinationRepository;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/notification-destinations/{id}/connection-test', name: 'admin_discord_webhook_connection_test', methods: ['POST'])]
final class DiscordWebhookConnectionTestController extends AbstractController
{
    public function __invoke(string $id, Request $request, NotificationDestinationRepository $repository, SendDiscordNotification $sender, RequireAdministratorReauthentication $reauthentication): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('discord_webhook_connection_test', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $destination = $this->destination($repository, $id);
            $sender->send($destination, ['content' => 'StreamNotifyBot: Discord Webhook接続確認']);
            $this->addFlash('success', 'Discord Webhookへのテスト通知を送信しました。');
        } catch (InvalidArgumentException|RuntimeException) {
            $this->addFlash('error', 'Discord Webhookへのテスト通知を送信できませんでした。');
        }

        return $this->redirectToRoute('admin_notification_destinations');
    }

    private function destination(NotificationDestinationRepository $repository, string $id): NotificationDestination
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('通知先IDが不正です。');
        }

        foreach ($repository->findAll() as $destination) {
            if ($destination->id === $id) {
                return $destination;
            }
        }

        throw new InvalidArgumentException('通知先が見つかりません。');
    }
}
