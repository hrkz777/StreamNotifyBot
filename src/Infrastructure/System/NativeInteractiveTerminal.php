<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\System\InteractiveTerminal;

final readonly class NativeInteractiveTerminal implements InteractiveTerminal
{
    public function hasInteractiveInput(): bool
    {
        return self::isTerminalStream('STDIN');
    }

    public function hasInteractiveOutput(): bool
    {
        return self::isTerminalStream('STDOUT');
    }

    private static function isTerminalStream(string $constantName): bool
    {
        if (!defined($constantName)) {
            return false;
        }

        $stream = constant($constantName);

        return is_resource($stream) && stream_isatty($stream);
    }
}
