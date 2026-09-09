<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhookイベント受信箱へCron処理用のリース状態を追加する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_events ADD processing_lease_token BINARY(16) DEFAULT NULL AFTER received_at');
        $this->addSql('ALTER TABLE webhook_events ADD processing_lease_until DATETIME(6) DEFAULT NULL AFTER processing_lease_token');
        $this->addSql('ALTER TABLE webhook_events ADD processed_at DATETIME(6) DEFAULT NULL AFTER processing_lease_until');
        $this->addSql('ALTER TABLE webhook_events ADD INDEX ix_webhook_events_pending (processed_at, processing_lease_until, received_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_events DROP INDEX ix_webhook_events_pending');
        $this->addSql('ALTER TABLE webhook_events DROP COLUMN processed_at');
        $this->addSql('ALTER TABLE webhook_events DROP COLUMN processing_lease_until');
        $this->addSql('ALTER TABLE webhook_events DROP COLUMN processing_lease_token');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
