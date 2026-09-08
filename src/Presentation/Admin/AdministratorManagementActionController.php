<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\DeactivateAdministrator;
use App\Application\Administration\DeleteAdministrator;
use App\Application\Administration\RequireAdministratorReauthentication;
use App\Infrastructure\Security\AdministratorSecurityUser;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/administrators/{administratorId}/{action}', name: 'admin_administrator_action', methods: ['POST'], requirements: ['administratorId' => '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', 'action' => 'deactivate|delete'])]
final class AdministratorManagementActionController extends AbstractController
{
    public function __invoke(
        Request $request,
        string $administratorId,
        string $action,
        RequireAdministratorReauthentication $reauthentication,
        DeactivateAdministrator $deactivate,
        DeleteAdministrator $delete,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser || $user->getId() === $administratorId) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid("administrator_management_{$action}_{$administratorId}", (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        $succeeded = match ($action) {
            'deactivate' => $deactivate->deactivate($administratorId),
            'delete' => $delete->delete($administratorId),
            default => throw new LogicException('未対応の管理者操作です。'),
        };
        $this->addFlash($succeeded ? 'success' : 'error', $succeeded ? '管理者の状態を更新しました。' : '管理者の状態を更新できませんでした。');

        return $this->redirectToRoute('admin_administrators');
    }
}
