<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Catalog\AgencyNotFound;
use App\Application\Catalog\RegisterStreamer;
use App\Application\Catalog\RegisterStreamerInput;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use App\Domain\Catalog\StreamerName;
use App\Domain\Catalog\SupportedLanguage;
use App\Domain\Catalog\UnsupportedPlatform;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/streamers', name: 'admin_streamer_register', methods: ['POST'])]
final class StreamerRegistrationController extends AbstractController
{
    public function __invoke(Request $request, RegisterStreamer $registerStreamer): Response
    {
        if (!$this->isCsrfTokenValid('streamer_register', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        try {
            $platform = Platform::tryFrom((string) $request->request->get('platform'))
                ?? throw new InvalidArgumentException('対応していないプラットフォームです。');
            $registerStreamer->register(new RegisterStreamerInput(
                (string) $request->request->get('agency_id'),
                SupportedLanguage::Japanese,
                self::optionalString($request->request->get('color_code')),
                true,
                [new StreamerName(SupportedLanguage::Japanese, (string) $request->request->get('name'))],
                $platform,
                (string) $request->request->get('registration_identifier'),
            ));
            $this->addFlash('success', '配信者を登録しました。Webhook購読は次回の更新ジョブで開始します。');
        } catch (AgencyNotFound|PlatformAccountNotFound|PlatformAccountResolutionFailed|UnsupportedPlatform|InvalidArgumentException) {
            $this->addFlash('error', '配信者、所属区分、またはプラットフォーム登録識別子が不正です。');
        }

        return $this->redirectToRoute('admin_streamers');
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
