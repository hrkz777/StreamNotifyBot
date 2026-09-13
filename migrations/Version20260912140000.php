<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhook公開コールバックURLを保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_callback_urls (
                id TINYINT UNSIGNED NOT NULL,
                callback_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_webhook_callback_urls PRIMARY KEY (id),
                CONSTRAINT ck_webhook_callback_urls_singleton CHECK (id = 1),
                CONSTRAINT ck_webhook_callback_urls_value CHECK (CHAR_LENGTH(callback_url) > 0)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_callback_urls');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
