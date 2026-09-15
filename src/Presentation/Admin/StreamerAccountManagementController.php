<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Catalog\AddPlatformAccountToStreamer;
use App\Domain\Catalog\Platform;
use App\Domain\Catalog\PlatformAccountIntegrationNotConfigured;
use App\Domain\Catalog\PlatformAccountNotFound;
use App\Domain\Catalog\PlatformAccountResolutionFailed;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StreamerAccountManagementController extends AbstractController
{
    #[Route('/admin/streamers/accounts', name: 'admin_streamer_accounts_add', methods: ['POST'])]
    public function add(Request $request, AddPlatformAccountToStreamer $addPlatformAccount): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMINISTRATOR');
        $streamerId = (string) $request->request->get('streamer_id');
        if (!$this->isCsrfTokenValid('streamer_account_add', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('CSRFトークンが不正です。');
        }

        try {
            $addPlatformAccount->add($streamerId, Platform::from((string) $request->request->get('platform')), (string) $request->request->get('registration_identifier'));
            $this->addFlash('success', 'プラットフォームアカウントを追加しました。Webhook購読は後続の定期処理で有効化されます。');
        } catch (PlatformAccountNotFound) {
            $this->addFlash('error', '指定したプラットフォームアカウントが見つかりません。');
        } catch (PlatformAccountIntegrationNotConfigured $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (PlatformAccountResolutionFailed) {
            $this->addFlash('error', 'プラットフォームアカウントを確認できませんでした。設定と入力内容を確認してください。');
        } catch (InvalidArgumentException|\ValueError $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_streamers');
    }
}
