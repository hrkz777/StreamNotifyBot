<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '配信者カタログとプラットフォームアカウントの初期スキーマを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE agencies (
                id BINARY(16) NOT NULL,
                code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                default_language_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                is_independent TINYINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_agencies PRIMARY KEY (id),
                CONSTRAINT uk_agencies_code UNIQUE (code),
                CONSTRAINT ck_agencies_default_language CHECK (default_language_code IN ('ja', 'en')),
                CONSTRAINT ck_agencies_independent CHECK (is_independent IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE agency_names (
                agency_id BINARY(16) NOT NULL,
                language_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
                short_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_agency_names PRIMARY KEY (agency_id, language_code),
                CONSTRAINT ck_agency_names_language CHECK (language_code IN ('ja', 'en')),
                CONSTRAINT ck_agency_names_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
                CONSTRAINT ck_agency_names_short_name CHECK (short_name IS NULL OR CHAR_LENGTH(TRIM(short_name)) > 0),
                CONSTRAINT fk_agency_names_agency FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE streamers (
                id BINARY(16) NOT NULL,
                agency_id BINARY(16) NOT NULL,
                default_language_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                color_code VARCHAR(7) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                is_enabled TINYINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_streamers PRIMARY KEY (id),
                CONSTRAINT ck_streamers_default_language CHECK (default_language_code IN ('ja', 'en')),
                CONSTRAINT ck_streamers_color CHECK (color_code IS NULL OR color_code REGEXP '^#[0-9A-Fa-f]{6}$'),
                CONSTRAINT ck_streamers_enabled CHECK (is_enabled IN (0, 1)),
                CONSTRAINT fk_streamers_agency FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE RESTRICT,
                INDEX ix_streamers_agency (agency_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE streamer_names (
                streamer_id BINARY(16) NOT NULL,
                language_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_streamer_names PRIMARY KEY (streamer_id, language_code),
                CONSTRAINT ck_streamer_names_language CHECK (language_code IN ('ja', 'en')),
                CONSTRAINT ck_streamer_names_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
                CONSTRAINT fk_streamer_names_streamer FOREIGN KEY (streamer_id) REFERENCES streamers (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE platform_accounts (
                id BINARY(16) NOT NULL,
                streamer_id BINARY(16) NOT NULL,
                platform_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                external_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                registration_identifier VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                display_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
                profile_url VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                icon_url VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                offline_image_url VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                is_enabled TINYINT UNSIGNED NOT NULL,
                resolved_at DATETIME(6) NOT NULL,
                api_data_refreshed_at DATETIME(6) DEFAULT NULL,
                api_data_expires_at DATETIME(6) DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_platform_accounts PRIMARY KEY (id),
                CONSTRAINT uk_platform_accounts_external UNIQUE (platform_code, external_id),
                CONSTRAINT ck_platform_accounts_platform CHECK (platform_code IN ('youtube', 'twitch', 'twitcasting')),
                CONSTRAINT ck_platform_accounts_enabled CHECK (is_enabled IN (0, 1)),
                CONSTRAINT ck_platform_accounts_external_id CHECK (CHAR_LENGTH(external_id) > 0),
                CONSTRAINT ck_platform_accounts_registration CHECK (CHAR_LENGTH(TRIM(registration_identifier)) > 0),
                CONSTRAINT fk_platform_accounts_streamer FOREIGN KEY (streamer_id) REFERENCES streamers (id) ON DELETE RESTRICT,
                INDEX ix_platform_accounts_streamer (streamer_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO agencies (
                id,
                code,
                default_language_code,
                is_independent,
                created_at,
                updated_at,
                lock_version
            ) VALUES (
                UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000001', '-', '')),
                'independent',
                'ja',
                1,
                UTC_TIMESTAMP(6),
                UTC_TIMESTAMP(6),
                0
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO agency_names (
                agency_id,
                language_code,
                name,
                short_name,
                created_at,
                updated_at,
                lock_version
            ) VALUES
                (
                    UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000001', '-', '')),
                    'ja',
                    '個人勢',
                    NULL,
                    UTC_TIMESTAMP(6),
                    UTC_TIMESTAMP(6),
                    0
                ),
                (
                    UNHEX(REPLACE('01990d4a-0000-7000-8000-000000000001', '-', '')),
                    'en',
                    'Independent',
                    NULL,
                    UTC_TIMESTAMP(6),
                    UTC_TIMESTAMP(6),
                    0
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_accounts');
        $this->addSql('DROP TABLE streamer_names');
        $this->addSql('DROP TABLE streamers');
        $this->addSql('DROP TABLE agency_names');
        $this->addSql('DROP TABLE agencies');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
