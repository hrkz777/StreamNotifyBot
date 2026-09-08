<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\RecordFailedAdministratorAuthenticationAttempt;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class AdministratorTwoFactorAuthenticationFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private RecordFailedAdministratorAuthenticationAttempt $recordFailedAttempt)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [TwoFactorAuthenticationEvents::FAILURE => 'onTwoFactorAuthenticationFailure'];
    }

    public function onTwoFactorAuthenticationFailure(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $sourceIp = $event->getRequest()->getClientIp();
        if (!$user instanceof AdministratorSecurityUser || $sourceIp === null) {
            return;
        }

        $this->recordFailedAttempt->record($user->getUserIdentifier(), $sourceIp);
    }
}
