<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Platform\YouTube;

use App\Application\Catalog\PlatformApiCredentialConfiguration;
use App\Application\Catalog\PlatformApiCredentialConfigurationLoader;
use App\Domain\Security\SecretDecryptionFailed;
use App\Infrastructure\Platform\YouTube\YouTubeWebhookSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YouTubeWebhookSignatureVerifierTest extends TestCase
{
    #[Test]
    public function itAcceptsTheExpectedSha1SignatureForTheUnmodifiedPayload(): void
    {
        $verifier = $this->verifier();
        $payload = '<feed>通知</feed>';

        self::assertTrue($verifier->isValid(
            $payload,
            'sha1=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db',
        ));
    }

    #[Test]
    public function itRejectsAnAlteredPayloadAndMalformedSignature(): void
    {
        $verifier = $this->verifier();

        self::assertFalse($verifier->isValid('<feed>改ざん</feed>', 'sha1=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db'));
        self::assertFalse($verifier->isValid('<feed>通知</feed>', 'sha256=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db'));
    }

    #[Test]
    public function itRejectsWhenTheStoredSecretCannotBeDecrypted(): void
    {
        $loader = $this->createStub(PlatformApiCredentialConfigurationLoader::class);
        $loader->method('load')->willThrowException(new SecretDecryptionFailed());

        self::assertFalse((new YouTubeWebhookSignatureVerifier($loader))->isValid('<feed>通知</feed>', 'sha1=e6186c8dfe4325ec508d7cffdfa5727a3c83e7db'));
    }

    private function verifier(): YouTubeWebhookSignatureVerifier
    {
        $loader = $this->createStub(PlatformApiCredentialConfigurationLoader::class);
        $loader->method('load')->willReturn(PlatformApiCredentialConfiguration::youTube('test-api-key', 'abcdefghijklmnopqrstuvwxyz0123456789'));

        return new YouTubeWebhookSignatureVerifier($loader);
    }
}
