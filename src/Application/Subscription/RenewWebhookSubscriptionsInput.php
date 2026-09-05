<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use InvalidArgumentException;

final readonly class RenewWebhookSubscriptionsInput
{
    public function __construct(
        public int $batchSize = 20,
        public int $maxRuntimeSeconds = 45,
        public int $maxAttempts = 8,
        public int $retryInitialDelaySeconds = 60,
        public int $retryMaxDelaySeconds = 3600,
        public float $backoffMultiplier = 2.0,
        public int $jitterPercent = 20,
        public int $leaseSeconds = 120,
        public int $verificationRetrySeconds = 300,
    ) {
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

        if ($backoffMultiplier < 1.0 || $backoffMultiplier > 10.0) {
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

        if ($verificationRetrySeconds < 1 || $verificationRetrySeconds > 86400) {
            throw new InvalidArgumentException('検証待ち再試行時間は1秒以上86400秒以下で指定してください。');
        }
    }
}
