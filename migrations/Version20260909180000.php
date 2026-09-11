<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discord通知先Webhook URLを暗号化して保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_destinations (
                id BINARY(16) NOT NULL,
                notification_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encrypted_webhook_url BLOB NOT NULL,
                encryption_nonce BINARY(24) NOT NULL,
                encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encryption_format_version SMALLINT UNSIGNED NOT NULL,
                is_enabled TINYINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_notification_destinations PRIMARY KEY (id),
                CONSTRAINT ck_notification_destinations_type CHECK (notification_type IN ('video_published', 'waiting_room_created', 'starting_in_thirty_minutes', 'scheduled_start', 'started', 'ended')),
                CONSTRAINT ck_notification_destinations_enabled CHECK (is_enabled IN (0, 1)),
                CONSTRAINT ck_notification_destinations_value CHECK (OCTET_LENGTH(encrypted_webhook_url) > 0),
                CONSTRAINT ck_notification_destinations_format CHECK (encryption_format_version > 0),
                INDEX ix_notification_destinations_type (notification_type, is_enabled)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_destinations');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
