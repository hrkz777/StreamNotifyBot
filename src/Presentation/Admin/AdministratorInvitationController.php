<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\IssueAdministratorInvitation;
use App\Domain\Administration\AdministratorRole;
use App\Infrastructure\Security\AdministratorSecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin/administrators/invitations', name: 'admin_administrator_invitation', methods: ['GET', 'POST'])]
final class AdministratorInvitationController extends AbstractController
{
    public function __invoke(Request $request, IssueAdministratorInvitation $issue, UrlGeneratorInterface $urls): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $url = null;
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('administrator_invitation', (string) $request->request->get('_csrf_token'))) {
            $user = $this->getUser();
            if ($user instanceof AdministratorSecurityUser) {
                $issued = $issue->issue($user->getId(), (string) $request->request->get('login_id'), (string) $request->request->get('display_name'), AdministratorRole::Administrator);
                $token = $issued->consumeToken();
                $url = $urls->generate('admin_administrator_invitation_acceptance', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
                sodium_memzero($token);
            }
        }

        $response = $this->render('admin/administrator_invitation.html.twig', ['invitation_url' => $url]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
