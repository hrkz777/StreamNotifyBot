<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Administration\RequireAdministratorReauthentication;
use App\Domain\Job\ConcurrentJobPolicyUpdate;
use App\Domain\Job\JobPolicy;
use App\Domain\Job\JobPolicyRepository;
use App\Domain\Job\JobType;
use App\Domain\System\Clock;
use App\Infrastructure\Security\AdministratorSecurityUser;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings/job-policies/{jobType}', name: 'admin_job_policy_update', methods: ['POST'], requirements: ['jobType' => 'webhook_event|stream_polling|subscription_renewal|notification|cleanup'])]
final class JobPolicyUpdateController extends AbstractController
{
    public function __invoke(Request $request, string $jobType, JobPolicyRepository $repository, RequireAdministratorReauthentication $reauthentication, Clock $clock): Response
    {
        $this->denyAccessUnlessGranted('ROLE_OWNER');
        $user = $this->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid("job_policy_update_{$jobType}", (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$reauthentication->isSatisfied($user->getId(), $user->getAuthenticationVersion(), $request->getSession()->getId())) {
            return $this->redirectToRoute('admin_reauthenticate');
        }

        try {
            $current = $repository->get(JobType::from($jobType));
            $repository->save(new JobPolicy(
                $current->id,
                $current->jobType,
                self::integer($request, 'batch_size'),
                self::integer($request, 'max_runtime_seconds'),
                self::integer($request, 'max_attempts'),
                self::integer($request, 'retry_initial_delay_seconds'),
                self::integer($request, 'retry_max_delay_seconds'),
                self::decimal($request, 'backoff_multiplier'),
                self::integer($request, 'jitter_percent'),
                self::integer($request, 'lease_seconds'),
                $request->request->getBoolean('is_enabled'),
                $clock->now(),
                $current->lockVersion,
            ));
            $this->addFlash('success', 'Cronジョブ設定を更新しました。');
        } catch (ConcurrentJobPolicyUpdate) {
            $this->addFlash('error', 'Cronジョブ設定は他の更新と競合しました。画面を再読み込みしてからやり直してください。');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'Cronジョブ設定の入力値が不正です。');
        }

        return $this->redirectToRoute('admin_settings');
    }

    private static function integer(Request $request, string $name): int
    {
        $value = $request->request->get($name);
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('数値入力が不正です。');
        }

        return (int) $value;
    }

    private static function decimal(Request $request, string $name): float
    {
        $value = $request->request->get($name);
        if (!is_string($value) || preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $value) !== 1) {
            throw new InvalidArgumentException('小数入力が不正です。');
        }

        return (float) $value;
    }
}
