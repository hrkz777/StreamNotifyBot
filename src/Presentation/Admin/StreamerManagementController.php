<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Catalog\AgencyNotFound;
use App\Application\Catalog\RegisterStreamer;
use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\AgencyRepository;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\StreamerCatalogRepository;
use App\Domain\Catalog\SupportedLanguage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ValueError;

#[Route('/admin/streamers', name: 'admin_streamers', methods: ['GET', 'POST'])]
final class StreamerManagementController extends AbstractController
{
    public function __invoke(
        Request $request,
        AgencyRepository $agencies,
        StreamerCatalogRepository $streamers,
        RegisterStreamer $registerStreamer,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('streamer_registration', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRFトークンが不正です。');
            }

            try {
                $registerStreamer->register(new RegisterStreamerInput(
                    (string) $request->request->get('agency_id'),
                    SupportedLanguage::Japanese,
                    self::optionalString($request->request->get('color')),
                    true,
                    self::names($request),
                    Platform::from((string) $request->request->get('platform')),
                    (string) $request->request->get('registration_identifier'),
                ));
                $this->addFlash('success', '配信者を登録しました。Webhook購読は後続の定期処理で有効化されます。');
            } catch (AgencyNotFound) {
                $this->addFlash('error', '選択した所属区分が見つかりません。画面を更新して選び直してください。');
            } catch (PlatformAccountNotFound) {
                $this->addFlash('error', '指定したプラットフォームアカウントが見つかりません。');
            } catch (PlatformAccountResolutionFailed) {
                $this->addFlash('error', 'プラットフォームアカウントを確認できませんでした。設定と入力内容を確認してください。');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'このプラットフォームアカウントは既に登録されています。');
            } catch (InvalidArgumentException|ValueError) {
                $this->addFlash('error', '入力内容を確認してください。');
            }

            return $this->redirectToRoute('admin_streamers');
        }

        $agenciesById = [];
        foreach ($agencies->findAll() as $agency) {
            $agenciesById[$agency->id] = $agency;
        }

        return $this->response([
            'agencies' => $agenciesById,
            'streamers' => $streamers->findAllStreamers(),
        ]);
    }

    /** @return list<StreamerName> */
    private static function names(Request $request): array
    {
        $names = [new StreamerName(SupportedLanguage::Japanese, (string) $request->request->get('name_ja'))];
        $englishName = self::optionalString($request->request->get('name_en'));
        if ($englishName !== null) {
            $names[] = new StreamerName(SupportedLanguage::English, $englishName);
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

    /** @param array<string, mixed> $parameters */
    private function response(array $parameters): Response
    {
        $contentSecurityPolicyNonce = base64_encode(random_bytes(18));
        $response = $this->render('admin/streamers.html.twig', $parameters + [
            'content_security_policy_nonce' => $contentSecurityPolicyNonce,
            'preview_status' => '配信者はプラットフォームアカウントを確認してからデータベースへ登録されます。',
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
}
