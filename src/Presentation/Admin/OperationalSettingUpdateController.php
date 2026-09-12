<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\System\Clock;
use App\Domain\System\ConcurrentOperationalSettingUpdate;
use App\Domain\System\OperationalSetting;
use App\Domain\System\OperationalSettingCatalog;
use App\Domain\System\OperationalSettingRepository;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings/operational-settings', name: 'admin_operational_settings_update', methods: ['POST'])]
final class OperationalSettingUpdateController extends AbstractController
{
    public function __invoke(Request $request, OperationalSettingRepository $repository, RequireAdministratorReauthentication $reauthentication, Clock $clock): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('operational_settings_update', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $current = [];
            foreach ($repository->findAll() as $setting) {
                $current[$setting->key] = $setting;
            }
            $values = self::values($request);
            self::assertQuotaAllocation($values);
            $now = $clock->now();
            $updates = [];
            foreach ($values as $key => $value) {
                $existing = $current[$key] ?? null;
                if (!$existing instanceof OperationalSetting) {
                    throw new InvalidArgumentException('運用設定の保存データが不足しています。');
                }
                $updates[] = new OperationalSetting($key, $value, $now, $existing->lockVersion);
            }
            $repository->saveAll($updates);
            $this->addFlash('success', '運用設定を更新しました。');
        } catch (ConcurrentOperationalSettingUpdate) {
            $this->addFlash('error', '運用設定は他の更新と競合しました。画面を再読み込みしてからやり直してください。');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', '運用設定の入力値が不正です。');
        }

        return $this->redirectToRoute('admin_settings');
    }

    /** @return array<string, int> */
    private static function values(Request $request): array
    {
        $values = [];
        foreach (OperationalSettingCatalog::all() as $key => $definition) {
            $input = $request->request->get($key);
            if (!is_string($input) || preg_match('/^[0-9]+$/D', $input) !== 1) {
                throw new InvalidArgumentException('数値入力が不正です。');
            }
            $value = (int) $input;
            if (!$definition->accepts($value)) {
                throw new InvalidArgumentException('運用設定の値が範囲外です。');
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @param array<string, int> $values */
    private static function assertQuotaAllocation(array $values): void
    {
        foreach (['youtube', 'twitch', 'twitcasting'] as $platform) {
            if ($values["quota_{$platform}_normal"] + $values["quota_{$platform}_reserved"] > $values["quota_{$platform}_allocation"]) {
                throw new InvalidArgumentException('API予算の内訳が割当量を超えています。');
            }
        }
    }
}
