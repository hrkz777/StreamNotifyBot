<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Administration;

use App\Domain\Administration\AuditLog;
use App\Domain\Administration\AuditLogResult;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    #[Test]
    public function itBuildsASanitizedSoleOwnerRecoveryAuditLog(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-07 00:10:00.123456+00:00');
        $auditLog = AuditLog::soleOwnerCredentialRecoverySucceeded(
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000401',
            '01990d4a-0000-7000-8000-000000000402',
            $occurredAt,
        );

        self::assertSame($occurredAt, $auditLog->occurredAt);
        self::assertNull($auditLog->actorAdministratorId);
        self::assertNull($auditLog->actorDisplaySnapshot);
        self::assertSame('administrator.sole_owner_credentials_recovered', $auditLog->actionCode);
        self::assertSame('administrator', $auditLog->targetType);
        self::assertSame('01990d4a-0000-7000-8000-000000000402', $auditLog->targetId);
        self::assertSame(AuditLogResult::Succeeded, $auditLog->result);
        self::assertSame('01990d4a-0000-7000-8000-000000000401', $auditLog->correlationId);
        self::assertNull($auditLog->sourceIp);
        self::assertNull($auditLog->userAgent);
        self::assertSame(
            '{"credential_types":["password","totp","recovery_codes"],"authentication_version_incremented":true,"sessions_revoked":true,"tokens_revoked":true}',
            $auditLog->changeSummary,
        );
        self::assertNull($auditLog->errorCode);
    }

    #[Test]
    public function itRejectsAListAsTheChangeSummaryRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('監査ログの変更要約はJSONオブジェクトとして指定してください。');

        new AuditLog(
            id: '01990d4a-0000-7000-8000-000000000401',
            occurredAt: new DateTimeImmutable('2026-09-07 00:10:00+00:00'),
            actorAdministratorId: null,
            actorDisplaySnapshot: null,
            actionCode: 'administrator.test',
            targetType: 'administrator',
            targetId: '01990d4a-0000-7000-8000-000000000402',
            result: AuditLogResult::Succeeded,
            correlationId: '01990d4a-0000-7000-8000-000000000401',
            sourceIp: null,
            userAgent: null,
            changeSummary: ['value'],
            errorCode: null,
        );
    }

    #[Test]
    public function itRejectsControlCharactersInTheUserAgent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User-Agentの形式が不正です。');

        new AuditLog(
            id: '01990d4a-0000-7000-8000-000000000401',
            occurredAt: new DateTimeImmutable('2026-09-07 00:10:00+00:00'),
            actorAdministratorId: null,
            actorDisplaySnapshot: null,
            actionCode: 'administrator.test',
            targetType: null,
            targetId: null,
            result: AuditLogResult::Denied,
            correlationId: '01990d4a-0000-7000-8000-000000000401',
            sourceIp: '::1',
            userAgent: "test-agent\nspoofed",
            changeSummary: null,
            errorCode: 'invalid_input',
        );
    }
}
