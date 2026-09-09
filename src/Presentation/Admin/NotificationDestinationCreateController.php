<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Stream\RegisterNotificationDestination;
use App\Domain\Stream\StreamNotificationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/notification-destinations/new', name: 'admin_notification_destination_create', methods: ['GET', 'POST'])]
final class NotificationDestinationCreateController extends AbstractController
{
    public function __invoke(Request $request, RegisterNotificationDestination $registerNotificationDestination): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('notification_destination_create', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRFトークンが不正です。');
            }
            try {
                $notificationType = StreamNotificationType::from((string) $request->request->get('notification_type'));
                $registerNotificationDestination->register($notificationType, (string) $request->request->get('webhook_url'), $request->request->getBoolean('is_enabled'));
                $this->addFlash('success', '通知先を登録しました。Webhook URLは再表示されません。');

                return $this->redirectToRoute('admin_notifications');
            } catch (\ValueError|\InvalidArgumentException) {
                $this->addFlash('error', '通知種別またはDiscord Webhook URLが不正です。');
            }
        }

        $response = $this->render('admin/notification_destination_create.html.twig', ['notification_types' => StreamNotificationType::cases()]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
