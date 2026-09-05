<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\AdministratorTotpAlgorithm;
use DateTimeImmutable;
use InvalidArgumentException;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

final readonly class OtpPhpAdministratorTotpAlgorithm implements AdministratorTotpAlgorithm
{
    private const int PERIOD_SECONDS = 30;
    private const int DIGITS = 6;
    private const string ALGORITHM = 'sha1';
    private const string ISSUER = 'StreamNotifyBot';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function generateSecret(): string
    {
        return rtrim(TOTP::generate($this->clock, 32)->getSecret(), '=');
    }

    public function provisioningUri(#[\SensitiveParameter] string $secret, string $accountName): string
    {
        $normalizedAccountName = trim($accountName);
        if ($normalizedAccountName === '') {
            throw new InvalidArgumentException('TOTPアカウント名を指定してください。');
        }

        $totp = $this->createTotp($secret);
        $totp->setIssuer(self::ISSUER);
        $totp->setLabel($normalizedAccountName);

        return $totp->getProvisioningUri();
    }

    public function matchTimeStep(
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $code,
        DateTimeImmutable $now,
    ): ?int {
        $normalizedCode = str_replace(' ', '', $code);
        if (preg_match('/^[0-9]{6}$/D', $normalizedCode) !== 1) {
            return null;
        }

        $totp = $this->createTotp($secret);
        $currentTimestamp = $now->getTimestamp();

        foreach ([1, 0, -1] as $offset) {
            $candidateTimestamp = $currentTimestamp + ($offset * self::PERIOD_SECONDS);
            if ($candidateTimestamp < 0) {
                continue;
            }

            if (hash_equals($totp->at($candidateTimestamp), $normalizedCode)) {
                return intdiv($candidateTimestamp, self::PERIOD_SECONDS);
            }
        }

        return null;
    }

    private function createTotp(#[\SensitiveParameter] string $secret): TOTP
    {
        if ($secret === '' || preg_match('/^[A-Z2-7]+$/D', $secret) !== 1) {
            throw new InvalidArgumentException('TOTP秘密値の形式が不正です。');
        }

        return TOTP::create(
            $secret,
            self::PERIOD_SECONDS,
            self::ALGORITHM,
            self::DIGITS,
            clock: $this->clock,
        );
    }
}
