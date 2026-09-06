<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Infrastructure\Security\AdministratorSecurityUser;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/admin')]
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof AdministratorSecurityUser) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $response = $this->render('admin/login.html.twig', [
            'last_login_id' => $authenticationUtils->getLastUsername(),
            'authentication_failed' => $authenticationUtils->getLastAuthenticationError() !== null,
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new LogicException('この処理はSymfony Securityのlogout listenerによって処理されます。');
    }
}
