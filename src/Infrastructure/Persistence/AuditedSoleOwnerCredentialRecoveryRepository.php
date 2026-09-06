<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AdministratorRecoveryCode;
use App\Domain\Administration\AdministratorTotpCredential;
use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogRepository;
use App\Domain\Administration\SoleOwnerCredentialRecoveryRepository;
use App\Domain\Administration\SoleOwnerRecoveryTarget;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SensitiveParameter;

final readonly class AuditedSoleOwnerCredentialRecoveryRepository implements SoleOwnerCredentialRecoveryRepository
{
    public function __construct(
        private Connection $connection,
        private DoctrineSoleOwnerCredentialRecoveryRepository $inner,
        private AuditLogRepository $auditLogRepository,
        private IdGenerator $idGenerator,
    ) {
    }

    public function findTarget(): SoleOwnerRecoveryTarget
    {
        return $this->inner->findTarget();
    }

    public function recover(
        SoleOwnerRecoveryTarget $expectedTarget,
        #[SensitiveParameter]
        string $passwordHash,
        #[SensitiveParameter]
        AdministratorTotpCredential $credential,
        #[SensitiveParameter]
        array $recoveryCodes,
        DateTimeImmutable $recoveredAt,
    ): void {
        $auditLogId = $this->idGenerator->generate();
        $auditLog = AuditLog::soleOwnerCredentialRecoverySucceeded(
            $auditLogId,
            $auditLogId,
            $expectedTarget->administratorId,
            $recoveredAt,
        );

        $this->connection->transactional(function () use (
            $expectedTarget,
            $passwordHash,
            $credential,
            $recoveryCodes,
            $recoveredAt,
            $auditLog,
        ): void {
            $this->inner->recover(
                $expectedTarget,
                $passwordHash,
                $credential,
                $recoveryCodes,
                $recoveredAt,
            );
            $this->auditLogRepository->append($auditLog);
        });
    }
}
