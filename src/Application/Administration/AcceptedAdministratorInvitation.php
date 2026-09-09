<?php

declare(strict_types=1);

namespace App\Application\Administration;

use InvalidArgumentException;
use LogicException;

final class AcceptedAdministratorInvitation
{
    /** @var list<string>|null */
    private ?array $recoveryCodes;

    /** @param list<string> $recoveryCodes */
    public function __construct(
        public readonly string $administratorId,
        #[\SensitiveParameter] array $recoveryCodes,
    ) {
        if (count($recoveryCodes) !== 10 || count(array_unique($recoveryCodes)) !== 10) {
            throw new InvalidArgumentException('受諾済み招待には重複しない10件の回復コードが必要です。');
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
