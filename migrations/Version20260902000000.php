<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhook購読状態の永続化スキーマを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_subscriptions (
                id BINARY(16) NOT NULL,
                platform_account_id BINARY(16) NOT NULL,
                subscription_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                external_subscription_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                expires_at DATETIME(6) DEFAULT NULL,
                renew_after DATETIME(6) DEFAULT NULL,
                last_attempted_at DATETIME(6) DEFAULT NULL,
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                processing_lease_token BINARY(16) DEFAULT NULL,
                processing_lease_until DATETIME(6) DEFAULT NULL,
                last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_webhook_subscriptions PRIMARY KEY (id),
                CONSTRAINT uk_webhook_subscriptions_account_type UNIQUE (platform_account_id, subscription_type),
                CONSTRAINT ck_webhook_subscriptions_type CHECK (subscription_type REGEXP '^[a-z0-9._:-]{1,64}$'),
                CONSTRAINT ck_webhook_subscriptions_status CHECK (status IN ('pending', 'active', 'error', 'expired')),
                CONSTRAINT ck_webhook_subscriptions_failure_count CHECK (failure_count >= 0),
                CONSTRAINT ck_webhook_subscriptions_lease CHECK (
                    (processing_lease_token IS NULL AND processing_lease_until IS NULL)
                    OR (processing_lease_token IS NOT NULL AND processing_lease_until IS NOT NULL)
                ),
                CONSTRAINT ck_webhook_subscriptions_renewal CHECK (
                    renew_after IS NULL OR expires_at IS NULL OR renew_after <= expires_at
                ),
                CONSTRAINT fk_webhook_subscriptions_account FOREIGN KEY (platform_account_id)
                    REFERENCES platform_accounts (id) ON DELETE RESTRICT,
                INDEX ix_webhook_subscriptions_due (status, renew_after, processing_lease_until, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_subscriptions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
