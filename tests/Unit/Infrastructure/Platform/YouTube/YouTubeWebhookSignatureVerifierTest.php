<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Infrastructure\Platform\YouTube\YouTubeWebhookSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YouTubeWebhookSignatureVerifierTest extends TestCase
{
    #[Test]
    public function itAcceptsTheExpectedSha1SignatureForTheUnmodifiedPayload(): void
    {
        $verifier = new YouTubeWebhookSignatureVerifier('abcdefghijklmnopqrstuvwxyz0123456789');
        $payload = '<feed>通知</feed>';

        self::assertTrue($verifier->isValid(
            $payload,
            'sha1=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db',
        ));
    }

    #[Test]
    public function itRejectsAnAlteredPayloadAndMalformedSignature(): void
    {
        $verifier = new YouTubeWebhookSignatureVerifier('abcdefghijklmnopqrstuvwxyz0123456789');

        self::assertFalse($verifier->isValid('<feed>改ざん</feed>', 'sha1=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db'));
        self::assertFalse($verifier->isValid('<feed>通知</feed>', 'sha256=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db'));
    }
}
