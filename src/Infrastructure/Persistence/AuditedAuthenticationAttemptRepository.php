<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Administration\AuthenticationAttempt;
use App\Domain\Administration\AuthenticationAttemptRepository;
use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogRepository;
use App\Domain\Administration\AuditLogResult;
use App\Domain\System\IdGenerator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class AuditedAuthenticationAttemptRepository implements AuthenticationAttemptRepository
{
    public function __construct(
        private Connection $connection,
        private DoctrineAuthenticationAttemptRepository $authenticationAttemptRepository,
        private AuditLogRepository $auditLogRepository,
        private IdGenerator $idGenerator,
    ) {
    }

    public function record(AuthenticationAttempt $attempt): void
    {
        $auditLog = new AuditLog(
            $this->idGenerator->generate(),
            $attempt->attemptedAt,
            null,
            null,
            'administrator.authentication',
            'authentication_attempt',
            $attempt->id,
            $attempt->result === 'success' ? AuditLogResult::Succeeded : AuditLogResult::Failed,
            $attempt->id,
            $attempt->sourceIp,
            null,
            [
                'result' => $attempt->result,
                'retry_delayed' => $attempt->retryAfter !== null,
            ],
            null,
        );

        $this->connection->transactional(function () use ($attempt, $auditLog): void {
            $this->authenticationAttemptRepository->record($attempt);
            $this->auditLogRepository->append($auditLog);
        });
    }

    public function countFailuresSince(string $loginIdentifierHash, string $sourceIp, DateTimeImmutable $since): int
    {
        return $this->authenticationAttemptRepository->countFailuresSince($loginIdentifierHash, $sourceIp, $since);
    }

    public function findRetryAfterSince(
        string $loginIdentifierHash,
        string $sourceIp,
        DateTimeImmutable $since,
        DateTimeImmutable $now,
    ): ?DateTimeImmutable {
        return $this->authenticationAttemptRepository->findRetryAfterSince($loginIdentifierHash, $sourceIp, $since, $now);
    }

    public function deleteBefore(DateTimeImmutable $before): int
    {
        return $this->authenticationAttemptRepository->deleteBefore($before);
    }
}
