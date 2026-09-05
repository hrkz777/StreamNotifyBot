<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\RenewWebhookSubscriptionsInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenewWebhookSubscriptionsInputTest extends TestCase
{
    #[Test]
    public function itAcceptsTheDocumentedDefaults(): void
    {
        $input = new RenewWebhookSubscriptionsInput();

        self::assertSame(20, $input->batchSize);
        self::assertSame(45, $input->maxRuntimeSeconds);
        self::assertSame(8, $input->maxAttempts);
        self::assertSame(120, $input->leaseSeconds);
        self::assertSame(300, $input->verificationRetrySeconds);
    }

    /**
     * @return iterable<string, array{array{
     *     batchSize?: int,
     *     maxRuntimeSeconds?: int,
     *     maxAttempts?: int,
     *     retryInitialDelaySeconds?: int,
     *     retryMaxDelaySeconds?: int,
     *     backoffMultiplier?: float,
     *     jitterPercent?: int,
     *     leaseSeconds?: int,
     *     verificationRetrySeconds?: int
     * }}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'empty batch' => [['batchSize' => 0]];
        yield 'short runtime' => [['maxRuntimeSeconds' => 4]];
        yield 'no attempts' => [['maxAttempts' => 0]];
        yield 'invalid initial delay' => [['retryInitialDelaySeconds' => 0]];
        yield 'maximum below initial' => [[
            'retryInitialDelaySeconds' => 60,
            'retryMaxDelaySeconds' => 59,
        ]];
        yield 'excessive multiplier' => [['backoffMultiplier' => 10.1]];
        yield 'non-finite multiplier' => [['backoffMultiplier' => NAN]];
        yield 'excessive jitter' => [['jitterPercent' => 51]];
        yield 'lease below correlated minimum' => [['leaseSeconds' => 74]];
        yield 'invalid verification delay' => [['verificationRetrySeconds' => 0]];
    }

    /**
     * @param array{
     *     batchSize?: int,
     *     maxRuntimeSeconds?: int,
     *     maxAttempts?: int,
     *     retryInitialDelaySeconds?: int,
     *     retryMaxDelaySeconds?: int,
     *     backoffMultiplier?: float,
     *     jitterPercent?: int,
     *     leaseSeconds?: int,
     *     verificationRetrySeconds?: int
     * } $arguments
     */
    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function itRejectsUnsafeValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RenewWebhookSubscriptionsInput(...$arguments);
    }
}
