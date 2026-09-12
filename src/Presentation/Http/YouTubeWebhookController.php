<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Subscription\ConfirmYouTubeWebhookSubscription;
use App\Application\Subscription\ReceiveYouTubeWebhookEvent;
use App\Infrastructure\Platform\YouTube\YouTubeWebhookSignatureVerifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class YouTubeWebhookController
{
    public function __construct(
        private ConfirmYouTubeWebhookSubscription $confirmSubscription,
        private ReceiveYouTubeWebhookEvent $receiveEvent,
        private YouTubeWebhookSignatureVerifier $signatureVerifier,
    ) {
    }

    #[Route('/webhooks/youtube/{subscriptionId}', name: 'app_webhook_youtube_verify', methods: ['GET'])]
    public function verify(string $subscriptionId, Request $request): Response
    {
        $mode = self::queryString($request, 'hub.mode');
        $topic = self::queryString($request, 'hub.topic');
        $challenge = self::queryString($request, 'hub.challenge');
        $leaseSeconds = self::leaseSeconds($request);

        if (
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $subscriptionId) !== 1
            || $mode !== 'subscribe'
            || $topic === null
            || $challenge === null
            || $leaseSeconds === null
            || $challenge === ''
            || strlen($challenge) > 1024
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->confirmSubscription->confirm($subscriptionId, $topic, $leaseSeconds)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($challenge, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/webhooks/youtube/{subscriptionId}', name: 'app_webhook_youtube_receive', methods: ['POST'])]
    public function receive(string $subscriptionId, Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('X-Hub-Signature');

        if (
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $subscriptionId) !== 1
            || $payload === ''
            || strlen($payload) > 1048576
            || !$this->signatureVerifier->isValid($payload, $signature)
            || !$this->receiveEvent->receive($subscriptionId, $payload)
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }

    private static function leaseSeconds(Request $request): ?int
    {
        $value = self::queryString($request, 'hub.lease_seconds');
        if ($value === null || preg_match('/^[1-9][0-9]{0,7}$/D', $value) !== 1) {
            return null;
        }

        $leaseSeconds = (int) $value;

        return $leaseSeconds <= 31536000 ? $leaseSeconds : null;
    }
}
