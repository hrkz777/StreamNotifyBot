<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\RecordFailedAdministratorAuthenticationAttempt;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final readonly class AdministratorAuthenticationAttemptSubscriber implements EventSubscriberInterface
{
    public function __construct(private RecordFailedAdministratorAuthenticationAttempt $recordFailedAttempt)
    {
    }

    /** @return array<class-string, string> */
    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => 'onLoginFailure'];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== 'main') {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/admin/login' || !$request->isMethod('POST')) {
            return;
        }

        $sourceIp = $request->getClientIp();
        if ($sourceIp === null) {
            return;
        }

        $this->recordFailedAttempt->record((string) $request->request->get('login_id'), $sourceIp);
    }
}
