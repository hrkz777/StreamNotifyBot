<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '所属区分のCSV置換に必要な無効化と論理削除状態を追加する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE agencies
                ADD is_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_independent,
                ADD deleted_at DATETIME(6) DEFAULT NULL AFTER is_enabled,
                ADD CONSTRAINT ck_agencies_enabled CHECK (is_enabled IN (0, 1)),
                ADD CONSTRAINT ck_agencies_deleted CHECK (deleted_at IS NULL OR is_enabled = 0),
                ADD INDEX ix_agencies_active (is_enabled, deleted_at, id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE agencies
                DROP INDEX ix_agencies_active,
                DROP CONSTRAINT ck_agencies_deleted,
                DROP CONSTRAINT ck_agencies_enabled,
                DROP COLUMN deleted_at,
                DROP COLUMN is_enabled
            SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
