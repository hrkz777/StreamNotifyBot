<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Catalog\AgencyAlreadyExists;
use App\Application\Catalog\CreateAgency;
use App\Domain\Catalog\AgencyName;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\SupportedLanguage;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/agencies', name: 'admin_agencies', methods: ['GET', 'POST'])]
final class AgencyManagementController extends AbstractController
{
    public function __invoke(Request $request, AgencyRepository $agencies, CreateAgency $createAgency): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('agency_creation', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRFトークンが不正です。');
            }

            try {
                $createAgency->create(
                    strtolower(trim((string) $request->request->get('code'))),
                    SupportedLanguage::fromInput((string) $request->request->get('default_language')),
                    $request->request->getBoolean('is_independent'),
                    self::names($request),
                );
                $this->addFlash('success', '所属区分を登録しました。');
            } catch (AgencyAlreadyExists) {
                $this->addFlash('error', 'この所属区分コードは既に登録されています。');
            } catch (InvalidArgumentException) {
                $this->addFlash('error', '入力内容を確認してください。');
            }

            return $this->redirectToRoute('admin_agencies');
        }

        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $response = $this->render('admin/agencies.html.twig', [
            'agencies' => $agencies->findAll(),
            'content_security_policy_nonce' => $contentSecurityPolicyNonce,
            'preview_status' => '所属区分はデータベースへ保存されます。',
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'nonce-{$contentSecurityPolicyNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    /** @return list<AgencyName> */
    private static function names(Request $request): array
    {
        $names = [new AgencyName(SupportedLanguage::Japanese, (string) $request->request->get('name_ja'), self::optionalString($request->request->get('short_name_ja')))];
        $englishName = self::optionalString($request->request->get('name_en'));
        if ($englishName !== null) {
            $names[] = new AgencyName(SupportedLanguage::English, $englishName, self::optionalString($request->request->get('short_name_en')));
        }

        return $names;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
