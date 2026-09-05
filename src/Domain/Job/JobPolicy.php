<?php

declare(strict_types=1);

namespace App\Domain\Job;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class JobPolicy
{
    public function __construct(
        public string $id,
        public JobType $jobType,
        public int $batchSize,
        public int $maxRuntimeSeconds,
        public int $maxAttempts,
        public int $retryInitialDelaySeconds,
        public int $retryMaxDelaySeconds,
        public float $backoffMultiplier,
        public int $jitterPercent,
        public int $leaseSeconds,
        public bool $isEnabled,
        public DateTimeImmutable $updatedAt,
        public int $lockVersion,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new InvalidArgumentException('ジョブ方針IDは小文字標準形式のUUIDv7で指定してください。');
        }

        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('処理件数は1件以上1000件以下で指定してください。');
        }

        if ($maxRuntimeSeconds < 5 || $maxRuntimeSeconds > 900) {
            throw new InvalidArgumentException('最大実行時間は5秒以上900秒以下で指定してください。');
        }

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidArgumentException('最大試行回数は1回以上20回以下で指定してください。');
        }

        if ($retryInitialDelaySeconds < 1 || $retryInitialDelaySeconds > 86400) {
            throw new InvalidArgumentException('初回再試行待機は1秒以上86400秒以下で指定してください。');
        }

        if ($retryMaxDelaySeconds < $retryInitialDelaySeconds || $retryMaxDelaySeconds > 604800) {
            throw new InvalidArgumentException('最大再試行待機は初回待機以上604800秒以下で指定してください。');
        }

        if (!is_finite($backoffMultiplier) || $backoffMultiplier < 1.0 || $backoffMultiplier > 10.0) {
            throw new InvalidArgumentException('バックオフ倍率は1.0以上10.0以下で指定してください。');
        }

        if ($jitterPercent < 0 || $jitterPercent > 50) {
            throw new InvalidArgumentException('ジッター率は0%以上50%以下で指定してください。');
        }

        $minimumLeaseSeconds = $maxRuntimeSeconds + max(30, (int) ceil($maxRuntimeSeconds * 0.2));
        if ($leaseSeconds < $minimumLeaseSeconds || $leaseSeconds > 3600) {
            throw new InvalidArgumentException(sprintf(
                'リース時間は%d秒以上3600秒以下で指定してください。',
                $minimumLeaseSeconds,
            ));
        }

        if ($lockVersion < 0) {
            throw new InvalidArgumentException('ロック版は0以上で指定してください。');
        }
    }
}
