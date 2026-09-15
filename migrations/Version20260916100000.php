<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'プラットフォームAPI資格情報を暗号化して保存するスキーマを作成する';
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
                encryption_format_version INT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT pk_platform_api_credentials PRIMARY KEY (id),
                CONSTRAINT uk_platform_api_credentials_platform_code UNIQUE (platform_code),
                CONSTRAINT ck_platform_api_credentials_platform_code CHECK (platform_code IN ('youtube', 'twitch', 'twitcasting')),
                CONSTRAINT ck_platform_api_credentials_format_version CHECK (encryption_format_version > 0)
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
