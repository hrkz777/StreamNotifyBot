<?php

declare(strict_types=1);

namespace App\Application\Stream;

use InvalidArgumentException;

final readonly class DeliverStreamNotificationsInput
{
    public function __construct(public int $batchSize, public int $leaseSeconds)
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('通知配送の処理件数は1件以上1000件以下で指定してください。');
        }
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('通知配送のリース時間は1秒以上3600秒以下で指定してください。');
        }
    }
}
