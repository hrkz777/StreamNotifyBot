<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '管理者トークンへ発行時の認証版を保存し、資格情報更新との世代整合性を保証する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE administrator_tokens ADD authentication_version INT UNSIGNED DEFAULT NULL AFTER created_by_administrator_id',
        );
        $this->addSql(<<<'SQL'
            UPDATE administrator_tokens AS token
            INNER JOIN administrators AS administrator ON administrator.id = token.administrator_id
            SET token.authentication_version = administrator.authentication_version
            WHERE token.purpose <> 'initial_setup'
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE administrator_tokens
            ADD CONSTRAINT ck_administrator_tokens_auth_version CHECK (
                (purpose = 'initial_setup' AND authentication_version IS NULL)
                OR (
                    purpose <> 'initial_setup'
                    AND authentication_version IS NOT NULL
                    AND authentication_version > 0
                )
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE administrator_tokens DROP CONSTRAINT ck_administrator_tokens_auth_version');
        $this->addSql('ALTER TABLE administrator_tokens DROP COLUMN authentication_version');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
