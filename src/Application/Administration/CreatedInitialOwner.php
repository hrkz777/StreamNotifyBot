<?php

declare(strict_types=1);

namespace App\Application\Administration;

use InvalidArgumentException;
use LogicException;

final class CreatedInitialOwner
{
    /** @var list<string>|null */
    private ?array $recoveryCodes;

    /** @param list<string> $recoveryCodes */
    public function __construct(
        public readonly string $administratorId,
        #[\SensitiveParameter] array $recoveryCodes,
    ) {
        if (count($recoveryCodes) !== 10) {
            throw new InvalidArgumentException('作成済み初期ownerには10件の回復コードが必要です。');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $administratorId) !== 1) {
            throw new InvalidArgumentException('作成済み初期ownerの管理者IDが不正です。');
        }

        if (count(array_unique($recoveryCodes)) !== 10) {
            throw new InvalidArgumentException('作成済み初期ownerの回復コードに重複があります。');
        }

        foreach ($recoveryCodes as $recoveryCode) {
            if (preg_match('/^[0-9A-F]{8}(?:-[0-9A-F]{8}){3}$/D', $recoveryCode) !== 1) {
                throw new InvalidArgumentException('作成済み初期ownerの回復コード形式が不正です。');
            }
        }

        $this->recoveryCodes = $recoveryCodes;
    }

    /** @return list<string> */
    public function consumeRecoveryCodes(): array
    {
        if ($this->recoveryCodes === null) {
            throw new LogicException('回復コードは既に取得されています。');
        }

        $recoveryCodes = $this->recoveryCodes;
        $this->eraseRecoveryCodes();

        return $recoveryCodes;
    }

    public function __destruct()
    {
        $this->eraseRecoveryCodes();
    }

    private function eraseRecoveryCodes(): void
    {
        if ($this->recoveryCodes === null) {
            return;
        }

        foreach ($this->recoveryCodes as &$recoveryCode) {
            sodium_memzero($recoveryCode);
        }

        unset($recoveryCode);
        $this->recoveryCodes = null;
    }
}
