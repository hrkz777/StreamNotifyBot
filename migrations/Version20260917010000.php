<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '通知設定の暗号化Webhook URLと対象配信者を永続化するスキーマを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_route_webhooks (
                id BINARY(16) NOT NULL,
                notification_route_id BINARY(16) NOT NULL,
                event_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encrypted_value BLOB NOT NULL,
                encryption_nonce BINARY(24) NOT NULL,
                encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encryption_format_version INT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT pk_notification_route_webhooks PRIMARY KEY (id),
                CONSTRAINT ck_notification_route_webhooks_event_type CHECK (event_type IN ('video', 'scheduled', 'live', 'ended')),
                CONSTRAINT ck_notification_route_webhooks_format_version CHECK (encryption_format_version > 0),
                CONSTRAINT fk_notification_route_webhooks_route FOREIGN KEY (notification_route_id) REFERENCES notification_routes (id) ON DELETE CASCADE,
                INDEX ix_notification_route_webhooks_route (notification_route_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_route_streamers (
                notification_route_id BINARY(16) NOT NULL,
                streamer_id BINARY(16) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                CONSTRAINT pk_notification_route_streamers PRIMARY KEY (notification_route_id, streamer_id),
                CONSTRAINT fk_notification_route_streamers_route FOREIGN KEY (notification_route_id) REFERENCES notification_routes (id) ON DELETE CASCADE,
                CONSTRAINT fk_notification_route_streamers_streamer FOREIGN KEY (streamer_id) REFERENCES streamers (id) ON DELETE RESTRICT,
                INDEX ix_notification_route_streamers_streamer (streamer_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_route_streamers');
        $this->addSql('DROP TABLE notification_route_webhooks');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
