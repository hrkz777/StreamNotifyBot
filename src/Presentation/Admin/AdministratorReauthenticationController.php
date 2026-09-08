<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\ReauthenticateAdministrator;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/reauthenticate', name: 'admin_reauthenticate', methods: ['GET', 'POST'])]
final class AdministratorReauthenticationController extends AbstractController
{
    public function __invoke(Request $request, ReauthenticateAdministrator $reauthenticate): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('administrator_reauthentication', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }

            try {
                if ($reauthenticate->reauthenticate(
                    $user->getId(),
                    $user->getAuthenticationVersion(),
                    $request->getSession()->getId(),
                    (string) $request->request->get('password'),
                    (string) $request->request->get('totp_code'),
                )) {
                    return $this->redirectToRoute('admin_dashboard');
                }
            } catch (InvalidArgumentException) {
            }

            $error = 'パスワードまたは認証コードを確認できませんでした。';
        }

        $response = $this->render('admin/reauthenticate.html.twig', ['error' => $error]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
