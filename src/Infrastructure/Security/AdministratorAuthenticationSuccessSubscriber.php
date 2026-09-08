<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\RecordSuccessfulAdministratorAuthenticationAttempt;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class AdministratorAuthenticationSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private RecordSuccessfulAdministratorAuthenticationAttempt $recordSuccessfulAttempt)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [TwoFactorAuthenticationEvents::COMPLETE => 'onTwoFactorAuthenticationComplete'];
    }

    public function onTwoFactorAuthenticationComplete(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $sourceIp = $event->getRequest()->getClientIp();
        if (!$user instanceof AdministratorSecurityUser || $sourceIp === null) {
            return;
        }

        $this->recordSuccessfulAttempt->record(
            $user->getId(),
            $user->getAuthenticationVersion(),
            $user->getUserIdentifier(),
            $sourceIp,
        );
    }
}
