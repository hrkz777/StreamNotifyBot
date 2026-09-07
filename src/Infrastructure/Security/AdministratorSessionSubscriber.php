<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Administration\ManageAdministratorSession;
use App\Domain\Administration\AdministratorSessionUnavailable;
use InvalidArgumentException;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class AdministratorSessionSubscriber implements EventSubscriberInterface
{
    private const string SESSION_FINGERPRINT_KEY = '_stream_notify_administrator_session';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ManageAdministratorSession $sessionManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return array<string, string|array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -20],
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof AdministratorSecurityUser) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            $this->terminate($event, $request, null);

            return;
        }

        $session = $request->getSession();
        $sessionId = $session->getId();
        if ($sessionId === '') {
            $this->terminate($event, $request, $session);

            return;
        }

        $fingerprint = ManageAdministratorSession::fingerprint($sessionId);
        $registeredFingerprint = $session->get(self::SESSION_FINGERPRINT_KEY);

        if ($registeredFingerprint === null) {
            if (!$this->startCurrentSession($request, $session, $user, $sessionId, $fingerprint)) {
                $this->terminate($event, $request, $session);
            }

            return;
        }

        if (!is_string($registeredFingerprint) || preg_match('/^[0-9a-f]{64}$/D', $registeredFingerprint) !== 1) {
            $this->terminate($event, $request, $session);

            return;
        }

        if (!hash_equals($registeredFingerprint, $fingerprint)) {
            $this->sessionManager->revokeFingerprint($registeredFingerprint);
            if (!$this->startCurrentSession($request, $session, $user, $sessionId, $fingerprint)) {
                $this->terminate($event, $request, $session);
            }

            return;
        }

        if (!$this->sessionManager->touch($user->getId(), $user->getAuthenticationVersion(), $sessionId)) {
            $this->terminate($event, $request, $session);
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $registeredFingerprint = $session->get(self::SESSION_FINGERPRINT_KEY);
        if (is_string($registeredFingerprint) && preg_match('/^[0-9a-f]{64}$/D', $registeredFingerprint) === 1) {
            $this->sessionManager->revokeFingerprint($registeredFingerprint);
        } elseif ($session->getId() !== '') {
            $this->sessionManager->revoke($session->getId());
        }

        $session->remove(self::SESSION_FINGERPRINT_KEY);
    }

    private function startCurrentSession(
        Request $request,
        SessionInterface $session,
        AdministratorSecurityUser $user,
        string $sessionId,
        string $fingerprint,
    ): bool {
        $sourceIp = $request->getClientIp();
        if ($sourceIp === null) {
            return false;
        }

        try {
            $startedFingerprint = $this->sessionManager->start(
                $user->getId(),
                $user->getAuthenticationVersion(),
                $sessionId,
                $sourceIp,
                $request->headers->get('User-Agent'),
            );
        } catch (AdministratorSessionUnavailable|InvalidArgumentException) {
            return false;
        }

        if (!hash_equals($fingerprint, $startedFingerprint)) {
            return false;
        }

        $session->set(self::SESSION_FINGERPRINT_KEY, $startedFingerprint);

        return true;
    }

    private function terminate(RequestEvent $event, Request $request, ?SessionInterface $session): void
    {
        if ($session !== null && $session->getId() !== '') {
            $this->sessionManager->revoke($session->getId());
            $session->invalidate();
        }

        $this->tokenStorage->setToken(null);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login')));
    }
}
