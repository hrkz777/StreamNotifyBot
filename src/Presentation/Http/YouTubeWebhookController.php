<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Subscription\ConfirmYouTubeWebhookSubscription;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class YouTubeWebhookController
{
    public function __construct(private ConfirmYouTubeWebhookSubscription $confirmSubscription)
    {
    }

    #[Route('/webhooks/youtube/{subscriptionId}', name: 'app_webhook_youtube_verify', methods: ['GET'])]
    public function verify(string $subscriptionId, Request $request): Response
    {
        $mode = self::queryString($request, 'hub.mode');
        $topic = self::queryString($request, 'hub.topic');
        $challenge = self::queryString($request, 'hub.challenge');

        if (
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $subscriptionId) !== 1
            || $mode !== 'subscribe'
            || $topic === null
            || $challenge === null
            || $challenge === ''
            || strlen($challenge) > 1024
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->confirmSubscription->confirm($subscriptionId, $topic)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($challenge, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }
}
