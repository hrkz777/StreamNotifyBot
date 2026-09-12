<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Subscription\WebhookCallbackUrl;
use App\Domain\Subscription\WebhookCallbackUrlRepository;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/platforms/webhook-callback-url', name: 'admin_webhook_callback_url_update', methods: ['POST'])]
final class WebhookCallbackUrlUpdateController extends AbstractController
{
    public function __invoke(Request $request, WebhookCallbackUrlRepository $repository, RequireAdministratorReauthentication $reauthentication): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('webhook_callback_url_update', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $value = $request->request->get('callback_url');
            if (!is_string($value)) {
                throw new InvalidArgumentException('Webhook公開URLが不正です。');
            }
            $repository->save(new WebhookCallbackUrl($value));
            $this->addFlash('success', 'Webhook公開URLを更新しました。');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'Webhook公開URLの入力値が不正です。');
        }

        return $this->redirectToRoute('admin_platforms');
    }
}
