<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\BeginAdministratorTotpEnrollment;
use App\Application\Administration\CreateInitialOwner;
use App\Domain\Administration\AdministratorPasswordRejected;
use App\Domain\Administration\InitialSetupAlreadyCompleted;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/setup/{token}', name: 'admin_initial_setup', methods: ['GET', 'POST'])]
final class InitialSetupController extends AbstractController
{
    public function __invoke(
        Request $request,
        string $token,
        BeginAdministratorTotpEnrollment $beginEnrollment,
        CreateInitialOwner $createInitialOwner,
    ): Response {
        $session = $request->getSession();
        $sessionKey = 'initial_setup.'.hash('sha256', $token);
        $error = null;
        $data = $session->get($sessionKey);
        if (!is_array($data) || !is_string($data['secret'] ?? null) || !is_string($data['uri'] ?? null)) {
            $enrollment = $beginEnrollment->begin('initial-owner');
            $data = ['secret' => $enrollment->secret, 'uri' => $enrollment->provisioningUri];
            $session->set($sessionKey, $data);
        }

        /** @var array{secret: string, uri: string} $data */

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('initial_setup', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }

            try {
                $result = $createInitialOwner->create(
                    (string) $request->request->get('login_id'),
                    (string) $request->request->get('display_name'),
                    (string) $request->request->get('password'),
                    $data['secret'],
                    (string) $request->request->get('totp_code'),
                    $token,
                );
                if ($result !== null) {
                    $session->remove($sessionKey);
                    $recoveryCodes = $result->consumeRecoveryCodes();

                    $response = $this->render('admin/initial_setup_complete.html.twig', ['recovery_codes' => $recoveryCodes]);
                    return self::secureResponse($response);
                }
                $error = 'セットアップトークンまたは認証コードを確認できませんでした。';
            } catch (AdministratorPasswordRejected|InitialSetupAlreadyCompleted|InvalidArgumentException $exception) {
                $error = '初期ownerを作成できませんでした。入力内容を確認してください。';
            }
        }

        $qrCodeSvg = (new Builder(writer: new SvgWriter(), data: $data['uri']))->build()->getString();
        $response = $this->render('admin/initial_setup.html.twig', ['token' => $token, 'provisioning_uri' => $data['uri'], 'qr_code_svg' => $qrCodeSvg, 'error' => $error]);
        return self::secureResponse($response);
    }

    private static function secureResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
