<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ポーリング、API予算、保持期間の運用設定を保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE operational_settings (setting_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, setting_value INT UNSIGNED NOT NULL, updated_at DATETIME(6) NOT NULL, lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0, CONSTRAINT pk_operational_settings PRIMARY KEY (setting_key), CONSTRAINT ck_operational_settings_key CHECK (setting_key REGEXP '^[a-z][a-z0-9_]{2,127}$')) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC");
        $this->addSql("INSERT INTO operational_settings (setting_key, setting_value, updated_at) VALUES ('polling_scheduled_youtube',900,UTC_TIMESTAMP(6)),('polling_scheduled_twitch',900,UTC_TIMESTAMP(6)),('polling_scheduled_twitcasting',900,UTC_TIMESTAMP(6)),('polling_imminent_youtube',60,UTC_TIMESTAMP(6)),('polling_imminent_twitch',300,UTC_TIMESTAMP(6)),('polling_imminent_twitcasting',300,UTC_TIMESTAMP(6)),('polling_live_youtube',60,UTC_TIMESTAMP(6)),('polling_live_twitch',300,UTC_TIMESTAMP(6)),('polling_live_twitcasting',300,UTC_TIMESTAMP(6)),('polling_ended_youtube',21600,UTC_TIMESTAMP(6)),('polling_ended_twitch',21600,UTC_TIMESTAMP(6)),('polling_ended_twitcasting',21600,UTC_TIMESTAMP(6)),('polling_error_youtube',300,UTC_TIMESTAMP(6)),('polling_error_twitch',300,UTC_TIMESTAMP(6)),('polling_error_twitcasting',300,UTC_TIMESTAMP(6)),('quota_youtube_allocation',10000,UTC_TIMESTAMP(6)),('quota_youtube_normal',6000,UTC_TIMESTAMP(6)),('quota_youtube_reserved',2000,UTC_TIMESTAMP(6)),('quota_twitch_allocation',100,UTC_TIMESTAMP(6)),('quota_twitch_normal',60,UTC_TIMESTAMP(6)),('quota_twitch_reserved',20,UTC_TIMESTAMP(6)),('quota_twitcasting_allocation',60,UTC_TIMESTAMP(6)),('quota_twitcasting_normal',36,UTC_TIMESTAMP(6)),('quota_twitcasting_reserved',12,UTC_TIMESTAMP(6)),('retention_delivery_results',30,UTC_TIMESTAMP(6)),('retention_api_data_after_notification',14,UTC_TIMESTAMP(6)),('retention_audit_logs',365,UTC_TIMESTAMP(6)),('retention_deleted_masters',30,UTC_TIMESTAMP(6))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE operational_settings');
    }
}
