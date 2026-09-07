<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class AdministratorTwoFactorFormRenderer implements TwoFactorFormRendererInterface
{
    public function __construct(private Environment $twig)
    {
    }

    /** @param array<string, mixed> $templateVars */
    public function renderForm(Request $request, array $templateVars): Response
    {
        $response = new Response($this->twig->render('admin/two_factor.html.twig', $templateVars));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
