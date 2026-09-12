<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'プラットフォームAPI接続情報を暗号化して保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_api_credentials (
                id BINARY(16) NOT NULL,
                platform_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encrypted_value BLOB NOT NULL,
                encryption_nonce BINARY(24) NOT NULL,
                encryption_key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                encryption_format_version SMALLINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT pk_platform_api_credentials PRIMARY KEY (id),
                CONSTRAINT uq_platform_api_credentials_platform UNIQUE (platform_code),
                CONSTRAINT ck_platform_api_credentials_platform CHECK (platform_code IN ('youtube', 'twitch', 'twitcasting')),
                CONSTRAINT ck_platform_api_credentials_value CHECK (OCTET_LENGTH(encrypted_value) > 0),
                CONSTRAINT ck_platform_api_credentials_format CHECK (encryption_format_version > 0)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_api_credentials');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
