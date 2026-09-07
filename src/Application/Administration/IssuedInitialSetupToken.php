<?php

declare(strict_types=1);

namespace App\Application\Administration;

use DateTimeImmutable;
use LogicException;

final class IssuedInitialSetupToken
{
    private ?string $token;

    public function __construct(
        #[\SensitiveParameter] string $token,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        $this->token = $token;
    }

    public function consumeToken(): string
    {
        if ($this->token === null) {
            throw new LogicException('初期設定トークンは既に取得されています。');
        }

        $token = $this->token;
        $this->token = null;

        return $token;
    }

    public function __destruct()
    {
        if ($this->token !== null) {
            sodium_memzero($this->token);
        }
    }
}
