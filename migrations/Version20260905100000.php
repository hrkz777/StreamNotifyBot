<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cronジョブの処理件数、実行時間、再試行、リース方針を作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE job_policies (
                id BINARY(16) NOT NULL,
                job_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                batch_size INT UNSIGNED NOT NULL,
                max_runtime_seconds INT UNSIGNED NOT NULL,
                max_attempts INT UNSIGNED NOT NULL,
                retry_initial_delay_seconds INT UNSIGNED NOT NULL,
                retry_max_delay_seconds INT UNSIGNED NOT NULL,
                backoff_multiplier DECIMAL(4,2) UNSIGNED NOT NULL,
                jitter_percent INT UNSIGNED NOT NULL,
                lease_seconds INT UNSIGNED NOT NULL,
                is_enabled TINYINT(1) UNSIGNED NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_job_policies PRIMARY KEY (id),
                CONSTRAINT uk_job_policies_type UNIQUE (job_type),
                CONSTRAINT ck_job_policies_type CHECK (
                    job_type IN ('webhook_event', 'stream_polling', 'subscription_renewal', 'notification', 'cleanup')
                ),
                CONSTRAINT ck_job_policies_batch_size CHECK (batch_size BETWEEN 1 AND 1000),
                CONSTRAINT ck_job_policies_max_runtime CHECK (max_runtime_seconds BETWEEN 5 AND 900),
                CONSTRAINT ck_job_policies_max_attempts CHECK (max_attempts BETWEEN 1 AND 20),
                CONSTRAINT ck_job_policies_retry_initial CHECK (retry_initial_delay_seconds BETWEEN 1 AND 86400),
                CONSTRAINT ck_job_policies_retry_max CHECK (
                    retry_max_delay_seconds BETWEEN retry_initial_delay_seconds AND 604800
                ),
                CONSTRAINT ck_job_policies_backoff CHECK (backoff_multiplier BETWEEN 1.0 AND 10.0),
                CONSTRAINT ck_job_policies_jitter CHECK (jitter_percent BETWEEN 0 AND 50),
                CONSTRAINT ck_job_policies_lease CHECK (
                    lease_seconds BETWEEN
                        max_runtime_seconds + GREATEST(30, CEIL(max_runtime_seconds * 0.2))
                        AND 3600
                ),
                CONSTRAINT ck_job_policies_enabled CHECK (is_enabled IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO job_policies (
                id,
                job_type,
                batch_size,
                max_runtime_seconds,
                max_attempts,
                retry_initial_delay_seconds,
                retry_max_delay_seconds,
                backoff_multiplier,
                jitter_percent,
                lease_seconds,
                is_enabled,
                updated_at,
                lock_version
            ) VALUES
                (UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000401', '-', '')), 'webhook_event', 50, 45, 8, 60, 3600, 2.0, 20, 120, 1, UTC_TIMESTAMP(6), 0),
                (UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000402', '-', '')), 'stream_polling', 20, 45, 5, 60, 3600, 2.0, 20, 120, 1, UTC_TIMESTAMP(6), 0),
                (UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000403', '-', '')), 'subscription_renewal', 20, 45, 8, 60, 3600, 2.0, 20, 120, 1, UTC_TIMESTAMP(6), 0),
                (UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000404', '-', '')), 'notification', 20, 45, 8, 60, 3600, 2.0, 20, 120, 1, UTC_TIMESTAMP(6), 0),
                (UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000405', '-', '')), 'cleanup', 100, 45, 20, 60, 3600, 2.0, 20, 120, 1, UTC_TIMESTAMP(6), 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE job_policies');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
