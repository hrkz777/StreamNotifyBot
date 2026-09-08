<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\FindAdministratorAuthenticationRetryAfter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AdministratorAuthenticationThrottleSubscriber implements EventSubscriberInterface
{
    public const string AUTHENTICATION_FAILED_SESSION_KEY = '_stream_notify_authentication_failed';

    public function __construct(
        private FindAdministratorAuthenticationRetryAfter $findRetryAfter,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 9]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/admin/login' || !$request->isMethod('POST')) {
            return;
        }

        $sourceIp = $request->getClientIp();
        if ($sourceIp === null || $this->findRetryAfter->find((string) $request->request->get('login_id'), $sourceIp) === null) {
            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->set(self::AUTHENTICATION_FAILED_SESSION_KEY, true);
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login')));
    }
}
