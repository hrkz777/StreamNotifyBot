<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\FindAdministratorAuthenticationRetryAfter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final readonly class AdministratorTwoFactorAuthenticationThrottleSubscriber implements EventSubscriberInterface
{
    public function __construct(private FindAdministratorAuthenticationRetryAfter $findRetryAfter)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [TwoFactorAuthenticationEvents::ATTEMPT => 'onTwoFactorAuthenticationAttempt'];
    }

    public function onTwoFactorAuthenticationAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $sourceIp = $event->getRequest()->getClientIp();
        if (!$user instanceof AdministratorSecurityUser || $sourceIp === null) {
            return;
        }

        if ($this->findRetryAfter->find($user->getUserIdentifier(), $sourceIp) !== null) {
            throw new BadCredentialsException();
        }
    }
}
