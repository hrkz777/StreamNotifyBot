<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '配信終了状態をプラットフォーム動画に保存できるようにする';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE platform_videos DROP CONSTRAINT ck_platform_videos_lifecycle, ADD CONSTRAINT ck_platform_videos_lifecycle CHECK (lifecycle_state IN ('none', 'upcoming', 'live', 'ended'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE platform_videos DROP CONSTRAINT ck_platform_videos_lifecycle, ADD CONSTRAINT ck_platform_videos_lifecycle CHECK (lifecycle_state IN ('none', 'upcoming', 'live'))");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
