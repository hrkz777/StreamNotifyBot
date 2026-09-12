<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'プラットフォームアカウントごとのポーリング実行時刻を保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_accounts ADD last_polled_at DATETIME(6) DEFAULT NULL AFTER api_data_expires_at, ADD INDEX ix_platform_accounts_polling (platform_code, is_enabled, last_polled_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_accounts DROP INDEX ix_platform_accounts_polling, DROP COLUMN last_polled_at');
    }
}
