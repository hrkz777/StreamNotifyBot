<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\Administration\AdministratorSession;
use App\Domain\Administration\AdministratorSessionRepository;
use App\Domain\Administration\AuthenticationPolicyRepository;
use App\Domain\System\Clock;
use App\Domain\System\IdGenerator;
use DateInterval;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class ManageAdministratorSession
{
    public function __construct(
        private AdministratorSessionRepository $sessionRepository,
        private AuthenticationPolicyRepository $authenticationPolicyRepository,
        private Clock $clock,
        private IdGenerator $idGenerator,
    ) {
    }

    public function start(
        string $administratorId,
        int $authenticationVersion,
        #[SensitiveParameter] string $sessionId,
        string $sourceIp,
        ?string $userAgent,
    ): string {
        $tokenHash = self::fingerprint($sessionId);
        $policy = $this->authenticationPolicyRepository->get();
        $now = $this->clock->now();
        $idleExpiresAt = $now->add(new DateInterval(sprintf('PT%dM', $policy->idleTimeoutMinutes)));
        $absoluteExpiresAt = $now->add(new DateInterval(sprintf('PT%dH', $policy->absoluteTimeoutHours)));

        $this->sessionRepository->start(new AdministratorSession(
            $this->idGenerator->generate(),
            $administratorId,
            $tokenHash,
            $authenticationVersion,
            $now,
            $now,
            $idleExpiresAt,
            $absoluteExpiresAt,
            $now,
            $sourceIp,
            self::normalizeUserAgent($userAgent),
            null,
        ));

        return $tokenHash;
    }

    public function touch(
        string $administratorId,
        int $authenticationVersion,
        #[SensitiveParameter] string $sessionId,
    ): bool {
        $policy = $this->authenticationPolicyRepository->get();
        $now = $this->clock->now();

        return $this->sessionRepository->touch(
            self::fingerprint($sessionId),
            $administratorId,
            $authenticationVersion,
            $now,
            $now->add(new DateInterval(sprintf('PT%dM', $policy->idleTimeoutMinutes))),
        );
    }

    public function revoke(#[SensitiveParameter] string $sessionId): void
    {
        $this->sessionRepository->revoke(self::fingerprint($sessionId), $this->clock->now());
    }

    public function revokeFingerprint(string $tokenHash): void
    {
        self::assertFingerprint($tokenHash);
        $this->sessionRepository->revoke($tokenHash, $this->clock->now());
    }

    public static function fingerprint(#[SensitiveParameter] string $sessionId): string
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('セッションIDが空です。');
        }

        return hash('sha256', $sessionId);
    }

    private static function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512, 'UTF-8');
    }

    private static function assertFingerprint(string $tokenHash): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('セッショントークンハッシュはSHA-256の小文字16進表現で指定してください。');
        }
    }
}
