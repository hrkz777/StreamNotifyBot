<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SymfonyのHTTPセッション本体をMariaDBへ保存する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE framework_sessions (
                sess_id VARBINARY(128) NOT NULL,
                sess_data BLOB NOT NULL,
                sess_lifetime INT UNSIGNED NOT NULL,
                sess_time INT UNSIGNED NOT NULL,
                CONSTRAINT pk_framework_sessions PRIMARY KEY (sess_id),
                INDEX ix_framework_sessions_expiry (sess_lifetime, sess_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE framework_sessions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
