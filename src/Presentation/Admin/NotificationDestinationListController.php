<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Stream\NotificationDestinationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/notification-destinations', name: 'admin_notification_destinations', methods: ['GET', 'POST'])]
final class NotificationDestinationListController extends AbstractController
{
    public function __invoke(Request $request, NotificationDestinationRepository $repository): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('notification_destination_enabled', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRFトークンが不正です。');
            }
            try {
                $updated = $repository->setEnabled((string) $request->request->get('id'), $request->request->getBoolean('is_enabled'));
                $this->addFlash($updated ? 'success' : 'error', $updated ? '通知先の有効状態を更新しました。' : '通知先が見つかりません。');
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', '通知先IDが不正です。');
            }

            return $this->redirectToRoute('admin_notification_destinations');
        }

        $response = $this->render('admin/notification_destination_list.html.twig', ['destinations' => $repository->findAll()]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
