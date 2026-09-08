<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorDeactivationRepository;
use App\Domain\Administration\AdministratorDeletionRepository;
use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogRepository;
use App\Domain\Administration\AuditLogResult;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class AuditedAdministratorManagementAction
{
    public function __construct(
        private Connection $connection,
        private AdministratorDeactivationRepository $deactivationRepository,
        private AdministratorDeletionRepository $deletionRepository,
        private AuditLogRepository $auditLogRepository,
        private IdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function execute(
        string $action,
        string $actorAdministratorId,
        string $actorDisplayName,
        string $targetAdministratorId,
        ?string $sourceIp,
        ?string $userAgent,
    ): bool {
        if (!in_array($action, ['deactivate', 'delete'], true)) {
            throw new InvalidArgumentException('未対応の管理者操作です。');
        }

        $occurredAt = $this->clock->now();
        $auditLogId = $this->idGenerator->generate();
        $auditLog = new AuditLog(
            $auditLogId,
            $occurredAt,
            $actorAdministratorId,
            $actorDisplayName,
            "administrator.{$action}d",
            'administrator',
            $targetAdministratorId,
            AuditLogResult::Succeeded,
            $auditLogId,
            $sourceIp,
            self::normalizeUserAgent($userAgent),
            [
                'authentication_version_incremented' => true,
                'sessions_revoked' => true,
                'unconsumed_tokens_revoked' => true,
                'totp_credential_removed' => $action === 'delete',
                'recovery_codes_removed' => $action === 'delete',
            ],
            null,
        );

        return $this->connection->transactional(function () use ($action, $targetAdministratorId, $occurredAt, $auditLog): bool {
            $succeeded = match ($action) {
                'deactivate' => $this->deactivationRepository->deactivate($targetAdministratorId, $occurredAt),
                'delete' => $this->deletionRepository->delete($targetAdministratorId, $occurredAt),
            };
            if ($succeeded) {
                $this->auditLogRepository->append($auditLog);
            }

            return $succeeded;
        });
    }

    private static function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512, 'UTF-8');
    }
}
