<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '管理者認証、セッション、認証試行、監査ログの永続化スキーマを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE administrators (
                id BINARY(16) NOT NULL,
                login_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                display_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
                role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                authentication_version INT UNSIGNED NOT NULL DEFAULT 1,
                password_changed_at DATETIME(6) DEFAULT NULL,
                totp_enrolled_at DATETIME(6) DEFAULT NULL,
                last_login_at DATETIME(6) DEFAULT NULL,
                disabled_at DATETIME(6) DEFAULT NULL,
                deleted_at DATETIME(6) DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_administrators PRIMARY KEY (id),
                CONSTRAINT uk_administrators_login_id UNIQUE (login_id),
                CONSTRAINT ck_administrators_login_id CHECK (login_id REGEXP '^[a-z0-9._-]{3,64}$'),
                CONSTRAINT ck_administrators_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) > 0),
                CONSTRAINT ck_administrators_role CHECK (role IN ('owner', 'administrator')),
                CONSTRAINT ck_administrators_status CHECK (status IN ('pending', 'active', 'disabled', 'deleted')),
                CONSTRAINT ck_administrators_password CHECK (password_hash IS NULL OR CHAR_LENGTH(password_hash) > 0),
                CONSTRAINT ck_administrators_authentication_version CHECK (authentication_version > 0),
                CONSTRAINT ck_administrators_active_credentials CHECK (
                    status <> 'active' OR (password_hash IS NOT NULL AND password_changed_at IS NOT NULL AND totp_enrolled_at IS NOT NULL)
                ),
                CONSTRAINT ck_administrators_disabled_at CHECK (status <> 'disabled' OR disabled_at IS NOT NULL),
                CONSTRAINT ck_administrators_deleted CHECK (status <> 'deleted' OR (deleted_at IS NOT NULL AND password_hash IS NULL))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE administrator_totp_credentials (
                administrator_id BINARY(16) NOT NULL,
                encrypted_value BLOB NOT NULL,
                encryption_nonce BINARY(24) NOT NULL,
                encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encryption_format_version INT UNSIGNED NOT NULL DEFAULT 1,
                last_accepted_time_step BIGINT UNSIGNED DEFAULT NULL,
                CONSTRAINT pk_administrator_totp_credentials PRIMARY KEY (administrator_id),
                CONSTRAINT ck_administrator_totp_encrypted CHECK (OCTET_LENGTH(encrypted_value) > 0),
                CONSTRAINT ck_administrator_totp_key_id CHECK (CHAR_LENGTH(encryption_key_id) > 0),
                CONSTRAINT ck_administrator_totp_format CHECK (encryption_format_version > 0),
                CONSTRAINT fk_administrator_totp_administrator FOREIGN KEY (administrator_id) REFERENCES administrators (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE administrator_recovery_codes (
                id BINARY(16) NOT NULL,
                administrator_id BINARY(16) NOT NULL,
                code_hash BINARY(32) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                used_at DATETIME(6) DEFAULT NULL,
                CONSTRAINT pk_administrator_recovery_codes PRIMARY KEY (id),
                CONSTRAINT uk_administrator_recovery_code UNIQUE (administrator_id, code_hash),
                CONSTRAINT fk_administrator_recovery_administrator FOREIGN KEY (administrator_id) REFERENCES administrators (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE administrator_tokens (
                id BINARY(16) NOT NULL,
                administrator_id BINARY(16) DEFAULT NULL,
                purpose VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                token_hash BINARY(32) NOT NULL,
                created_by_administrator_id BINARY(16) DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                expires_at DATETIME(6) NOT NULL,
                consumed_at DATETIME(6) DEFAULT NULL,
                revoked_at DATETIME(6) DEFAULT NULL,
                CONSTRAINT pk_administrator_tokens PRIMARY KEY (id),
                CONSTRAINT uk_administrator_tokens_hash UNIQUE (token_hash),
                CONSTRAINT ck_administrator_tokens_purpose CHECK (purpose IN ('initial_setup', 'invitation', 'credential_reset')),
                CONSTRAINT ck_administrator_tokens_expiry CHECK (expires_at > created_at),
                CONSTRAINT ck_administrator_tokens_subject CHECK (purpose = 'initial_setup' OR administrator_id IS NOT NULL),
                CONSTRAINT fk_administrator_tokens_administrator FOREIGN KEY (administrator_id) REFERENCES administrators (id) ON DELETE CASCADE,
                CONSTRAINT fk_administrator_tokens_creator FOREIGN KEY (created_by_administrator_id) REFERENCES administrators (id) ON DELETE SET NULL,
                INDEX ix_administrator_tokens_administrator (administrator_id),
                INDEX ix_administrator_tokens_creator (created_by_administrator_id),
                INDEX ix_administrator_tokens_expiry (expires_at, consumed_at, revoked_at, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE administrator_sessions (
                id BINARY(16) NOT NULL,
                administrator_id BINARY(16) NOT NULL,
                token_hash BINARY(32) NOT NULL,
                authentication_version INT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                last_activity_at DATETIME(6) NOT NULL,
                idle_expires_at DATETIME(6) NOT NULL,
                absolute_expires_at DATETIME(6) NOT NULL,
                reauthenticated_at DATETIME(6) DEFAULT NULL,
                source_ip INET6 NOT NULL,
                user_agent VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                revoked_at DATETIME(6) DEFAULT NULL,
                CONSTRAINT pk_administrator_sessions PRIMARY KEY (id),
                CONSTRAINT uk_administrator_sessions_hash UNIQUE (token_hash),
                CONSTRAINT ck_administrator_sessions_auth_version CHECK (authentication_version > 0),
                CONSTRAINT ck_administrator_sessions_idle_expiry CHECK (idle_expires_at > created_at),
                CONSTRAINT ck_administrator_sessions_absolute_expiry CHECK (absolute_expires_at >= idle_expires_at),
                CONSTRAINT fk_administrator_sessions_administrator FOREIGN KEY (administrator_id) REFERENCES administrators (id) ON DELETE CASCADE,
                INDEX ix_administrator_sessions_administrator (administrator_id, revoked_at, absolute_expires_at)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE authentication_attempts (
                id BINARY(16) NOT NULL,
                login_identifier_hash BINARY(32) NOT NULL,
                source_ip INET6 NOT NULL,
                attempted_at DATETIME(6) NOT NULL,
                result VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                retry_after DATETIME(6) DEFAULT NULL,
                CONSTRAINT pk_authentication_attempts PRIMARY KEY (id),
                CONSTRAINT ck_authentication_attempts_result CHECK (CHAR_LENGTH(result) > 0),
                INDEX ix_auth_attempts_login_time (login_identifier_hash, attempted_at, id),
                INDEX ix_auth_attempts_ip_time (source_ip, attempted_at, id),
                INDEX ix_auth_attempts_time (attempted_at, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE authentication_policies (
                id BINARY(16) NOT NULL,
                idle_timeout_minutes INT UNSIGNED NOT NULL DEFAULT 30,
                absolute_timeout_hours INT UNSIGNED NOT NULL DEFAULT 12,
                reauthentication_minutes INT UNSIGNED NOT NULL DEFAULT 10,
                failure_window_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                failure_threshold INT UNSIGNED NOT NULL DEFAULT 5,
                maximum_delay_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                initial_setup_completed_at DATETIME(6) DEFAULT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_authentication_policies PRIMARY KEY (id),
                CONSTRAINT ck_authentication_policies_singleton CHECK (id = X'01990D4A000070008000000000000002'),
                CONSTRAINT ck_authentication_policies_idle CHECK (idle_timeout_minutes BETWEEN 5 AND 120),
                CONSTRAINT ck_authentication_policies_absolute CHECK (absolute_timeout_hours BETWEEN 1 AND 24),
                CONSTRAINT ck_authentication_policies_expiry_order CHECK (absolute_timeout_hours * 60 >= idle_timeout_minutes),
                CONSTRAINT ck_authentication_policies_reauthentication CHECK (reauthentication_minutes > 0),
                CONSTRAINT ck_authentication_policies_failure_window CHECK (failure_window_minutes > 0),
                CONSTRAINT ck_authentication_policies_failure_threshold CHECK (failure_threshold > 0),
                CONSTRAINT ck_authentication_policies_maximum_delay CHECK (maximum_delay_minutes > 0)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_logs (
                id BINARY(16) NOT NULL,
                occurred_at DATETIME(6) NOT NULL,
                actor_administrator_id BINARY(16) DEFAULT NULL,
                actor_display_snapshot VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
                action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                target_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                target_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                correlation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                source_ip INET6 DEFAULT NULL,
                user_agent VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                change_summary LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                CONSTRAINT pk_audit_logs PRIMARY KEY (id),
                CONSTRAINT ck_audit_logs_action_code CHECK (CHAR_LENGTH(action_code) > 0),
                CONSTRAINT ck_audit_logs_result CHECK (result IN ('succeeded', 'failed', 'denied')),
                CONSTRAINT ck_audit_logs_correlation_id CHECK (CHAR_LENGTH(correlation_id) > 0),
                CONSTRAINT ck_audit_logs_change_summary CHECK (
                    change_summary IS NULL OR (
                        OCTET_LENGTH(change_summary) <= 65535
                        AND JSON_VALID(change_summary)
                        AND JSON_TYPE(change_summary) = 'OBJECT'
                    )
                ),
                CONSTRAINT fk_audit_logs_administrator FOREIGN KEY (actor_administrator_id) REFERENCES administrators (id) ON DELETE RESTRICT,
                INDEX ix_audit_logs_occurred (occurred_at, id),
                INDEX ix_audit_logs_actor (actor_administrator_id, occurred_at, id),
                INDEX ix_audit_logs_target (target_type, target_id, occurred_at, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
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
                UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000002', '-', '')),
                30,
                12,
                10,
                15,
                5,
                15,
                NULL,
                UTC_TIMESTAMP(6),
                0
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE authentication_policies');
        $this->addSql('DROP TABLE authentication_attempts');
        $this->addSql('DROP TABLE administrator_sessions');
        $this->addSql('DROP TABLE administrator_tokens');
        $this->addSql('DROP TABLE administrator_recovery_codes');
        $this->addSql('DROP TABLE administrator_totp_credentials');
        $this->addSql('DROP TABLE administrators');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
