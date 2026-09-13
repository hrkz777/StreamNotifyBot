<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '通知設定のメタデータを永続化するスキーマを作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_routes (
                id BINARY(16) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(200) DEFAULT NULL,
                color VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                is_enabled TINYINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_notification_routes PRIMARY KEY (id),
                CONSTRAINT uk_notification_routes_name UNIQUE (name),
                CONSTRAINT ck_notification_routes_color CHECK (color IN ('purple', 'blue', 'pink', 'orange')),
                CONSTRAINT ck_notification_routes_enabled CHECK (is_enabled IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_routes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
