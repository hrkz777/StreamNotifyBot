<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use InvalidArgumentException;

final readonly class ProcessWebhookEventsInput
{
    public function __construct(
        public int $batchSize,
        public int $maxRuntimeSeconds,
        public int $leaseSeconds,
    ) {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Webhookイベントの処理件数は1件以上1000件以下で指定してください。');
        }

        if ($maxRuntimeSeconds < 1 || $maxRuntimeSeconds > 900) {
            throw new InvalidArgumentException('Webhookイベントの最大実行時間は1秒以上900秒以下で指定してください。');
        }

        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Webhookイベントのリース時間は1秒以上3600秒以下で指定してください。');
        }
    }
}
