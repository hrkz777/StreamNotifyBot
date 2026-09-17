<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Notification\SaveNotificationRouteConfiguration;
use App\Domain\Notification\NotificationRouteRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationRouteManagementController extends AbstractController
{
    #[Route('/admin/notifications/save', name: 'admin_notification_save', methods: ['POST'])]
    public function save(Request $request, SaveNotificationRouteConfiguration $save): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');
        if (!$this->isCsrfTokenValid('notification_route_save', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'フォームの有効期限が切れました。画面を再読み込みしてからもう一度実行してください。');

            return $this->redirectToRoute('admin_notifications');
        }
        try {
            $save->save($request->request->all());
            $this->addFlash('success', '通知設定を保存しました。Webhook URLは暗号化して保存されています。');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', '同じ通知設定名がすでに登録されています。');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_notifications');
    }

    #[Route('/admin/notifications/{id}/remove', name: 'admin_notification_remove', methods: ['POST'])]
    public function remove(string $id, Request $request, NotificationRouteRepository $routes): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');
        if (!$this->isCsrfTokenValid('notification_route_remove_'.$id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'フォームの有効期限が切れました。画面を再読み込みしてからもう一度実行してください。');

            return $this->redirectToRoute('admin_notifications');
        }
        try {
            $routes->remove($id);
            $this->addFlash('success', '通知設定を削除しました。');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', '削除対象の通知設定が見つかりません。');
        }

        return $this->redirectToRoute('admin_notifications');
    }
}
