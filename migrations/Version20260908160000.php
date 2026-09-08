<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '検証済みWebhook通知を冪等に受け渡すイベント受信箱を作成する';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_events (
                id BINARY(16) NOT NULL,
                subscription_id BINARY(16) NOT NULL,
                payload_hash BINARY(32) NOT NULL,
                payload MEDIUMBLOB NOT NULL,
                received_at DATETIME(6) NOT NULL,
                CONSTRAINT pk_webhook_events PRIMARY KEY (id),
                CONSTRAINT uk_webhook_events_subscription_payload UNIQUE (subscription_id, payload_hash),
                CONSTRAINT fk_webhook_events_subscription FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions (id) ON DELETE RESTRICT,
                INDEX ix_webhook_events_received (received_at, id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_events');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
