<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'プラットフォームから取得した配信動画の最新状態を保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_videos (
                id BINARY(16) NOT NULL,
                platform_account_id BINARY(16) NOT NULL,
                external_video_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                title VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
                published_at DATETIME(6) NOT NULL,
                scheduled_start_at DATETIME(6) DEFAULT NULL,
                actual_start_at DATETIME(6) DEFAULT NULL,
                actual_end_at DATETIME(6) DEFAULT NULL,
                thumbnail_url VARCHAR(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                lifecycle_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                first_observed_at DATETIME(6) NOT NULL,
                last_observed_at DATETIME(6) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_platform_videos PRIMARY KEY (id),
                CONSTRAINT uk_platform_videos_account_external UNIQUE (platform_account_id, external_video_id),
                CONSTRAINT ck_platform_videos_lifecycle CHECK (lifecycle_state IN ('none', 'upcoming', 'live')),
                CONSTRAINT fk_platform_videos_account FOREIGN KEY (platform_account_id) REFERENCES platform_accounts (id) ON DELETE RESTRICT,
                INDEX ix_platform_videos_state (platform_account_id, lifecycle_state, last_observed_at)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_videos');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
