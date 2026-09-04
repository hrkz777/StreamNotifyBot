<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdministratorPersistenceSchemaTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itSeedsTheSecureAuthenticationPolicyDefaults(): void
    {
        $policy = $this->connection->fetchAssociative(
            'SELECT idle_timeout_minutes, absolute_timeout_hours, reauthentication_minutes,
                    failure_window_minutes, failure_threshold, maximum_delay_minutes,
                    initial_setup_completed_at
             FROM authentication_policies',
        );

        self::assertIsArray($policy);
        self::assertSame(30, $policy['idle_timeout_minutes']);
        self::assertSame(12, $policy['absolute_timeout_hours']);
        self::assertSame(10, $policy['reauthentication_minutes']);
        self::assertSame(15, $policy['failure_window_minutes']);
        self::assertSame(5, $policy['failure_threshold']);
        self::assertSame(15, $policy['maximum_delay_minutes']);
        self::assertNull($policy['initial_setup_completed_at']);
    }

    #[Test]
    public function itRejectsAnInvalidAdministratorLoginId(): void
    {
        $this->expectException(DriverException::class);

        $this->insertAdministrator('Invalid Login', 'pending');
    }

    #[Test]
    public function itRejectsASecondAuthenticationPolicy(): void
    {
        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO authentication_policies (
                    id,
                    idle_timeout_minutes,
                    absolute_timeout_hours,
                    reauthentication_minutes,
                    failure_window_minutes,
                    failure_threshold,
                    maximum_delay_minutes,
                    initial_setup_completed_at,
                    updated_at,
                    lock_version
                ) VALUES (
                    UNHEX(REPLACE(:id, '-', '')),
                    30,
                    12,
                    10,
                    15,
                    5,
                    15,
                    NULL,
                    :updatedAt,
                    0
                )
                SQL,
            [
                'id' => '01990d4a-0000-7000-8000-000000000099',
                'updatedAt' => '2026-09-04 00:00:00.000000',
            ],
        );
    }

    #[Test]
    public function itRejectsAnActiveAdministratorWithoutCredentials(): void
    {
        $this->expectException(DriverException::class);

        $this->insertAdministrator('active.owner', 'active');
    }

    #[Test]
    public function itRejectsANonObjectAuditChangeSummary(): void
    {
        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO audit_logs (
                    id,
                    occurred_at,
                    actor_administrator_id,
                    actor_display_snapshot,
                    action_code,
                    target_type,
                    target_id,
                    result,
                    correlation_id,
                    source_ip,
                    user_agent,
                    change_summary,
                    error_code
                ) VALUES (
                    UNHEX(REPLACE(:id, '-', '')),
                    :occurredAt,
                    NULL,
                    NULL,
                    :actionCode,
                    NULL,
                    NULL,
                    :result,
                    :correlationId,
                    NULL,
                    NULL,
                    :changeSummary,
                    NULL
                )
                SQL,
            [
                'id' => '01990d4a-0000-7000-8000-000000000101',
                'occurredAt' => '2026-09-04 00:00:00.000000',
                'actionCode' => 'authentication.test',
                'result' => 'succeeded',
                'correlationId' => '01990d4a-0000-7000-8000-000000000102',
                'changeSummary' => '[]',
            ],
        );
    }

    private function insertAdministrator(string $loginId, string $status): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO administrators (
                    id,
                    login_id,
                    display_name,
                    role,
                    status,
                    password_hash,
                    authentication_version,
                    password_changed_at,
                    totp_enrolled_at,
                    created_at,
                    updated_at,
                    lock_version
                ) VALUES (
                    UNHEX(REPLACE(:id, '-', '')),
                    :loginId,
                    :displayName,
                    :role,
                    :status,
                    NULL,
                    1,
                    NULL,
                    NULL,
                    :createdAt,
                    :updatedAt,
                    0
                )
                SQL,
            [
                'id' => '01990d4a-0000-7000-8000-000000000100',
                'loginId' => $loginId,
                'displayName' => 'テスト管理者',
                'role' => 'owner',
                'status' => $status,
                'createdAt' => '2026-09-04 00:00:00.000000',
                'updatedAt' => '2026-09-04 00:00:00.000000',
            ],
        );
    }
}
