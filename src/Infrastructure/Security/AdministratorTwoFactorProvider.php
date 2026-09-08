<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Administration\AdministratorTotpVerifier;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;

final readonly class AdministratorTwoFactorProvider implements TwoFactorProviderInterface
{
    public function __construct(
        private AdministratorTotpVerifier $verifyAdministratorTotp,
        private AdministratorTwoFactorFormRenderer $formRenderer,
    ) {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        return $context->getUser() instanceof AdministratorSecurityUser;
    }

    public function needsPreparation(): bool
    {
        return false;
    }

    public function prepareAuthentication(object $user): void
    {
    }

    public function validateAuthenticationCode(object $user, #[\SensitiveParameter] string $authenticationCode): bool
    {
        return $user instanceof AdministratorSecurityUser
            && $this->verifyAdministratorTotp->verify($user->getId(), $authenticationCode);
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->formRenderer;
    }
}
