<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '配信通知を重複なく送信するためのアウトボックスを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stream_notification_outbox (
                id BINARY(16) NOT NULL,
                platform_video_id BINARY(16) NOT NULL,
                notification_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payload_hash BINARY(32) NOT NULL,
                status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                occurred_at DATETIME(6) NOT NULL,
                processing_lease_token BINARY(16) DEFAULT NULL,
                processing_lease_until DATETIME(6) DEFAULT NULL,
                sent_at DATETIME(6) DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_stream_notification_outbox PRIMARY KEY (id),
                CONSTRAINT uk_stream_notification_outbox_video_type UNIQUE (platform_video_id, notification_type),
                CONSTRAINT ck_stream_notification_outbox_type CHECK (notification_type IN ('video_published', 'waiting_room_created', 'starting_in_thirty_minutes', 'scheduled_start', 'started', 'ended')),
                CONSTRAINT ck_stream_notification_outbox_status CHECK (status IN ('pending', 'sent', 'suppressed')),
                CONSTRAINT fk_stream_notification_outbox_video FOREIGN KEY (platform_video_id) REFERENCES platform_videos (id) ON DELETE RESTRICT,
                INDEX ix_stream_notification_outbox_pending (status, processing_lease_until, occurred_at, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stream_notification_outbox');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
