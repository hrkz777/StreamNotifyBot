<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticationAttemptThrottler
{
    public function retryAfter(
        AuthenticationPolicy $policy,
        int $recentFailureCount,
        DateTimeImmutable $attemptedAt,
    ): ?DateTimeImmutable {
        if ($recentFailureCount < 0) {
            throw new InvalidArgumentException('直近の認証失敗件数は0以上で指定してください。');
        }

        if ($recentFailureCount < $policy->failureThreshold) {
            return null;
        }

        $delayMinutes = 1;
        for ($index = $policy->failureThreshold; $index < $recentFailureCount; ++$index) {
            if ($delayMinutes >= $policy->maximumDelayMinutes) {
                break;
            }

            $delayMinutes = min($delayMinutes * 2, $policy->maximumDelayMinutes);
        }

        return $attemptedAt->modify(sprintf('+%d minutes', $delayMinutes));
    }
}
