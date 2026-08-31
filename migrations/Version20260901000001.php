<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add storeUuid and metadata to common_content for Authorization pilot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_content ADD store_uuid VARCHAR(36) DEFAULT NULL, ADD metadata JSON DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_common_content_store_uuid ON common_content (store_uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_common_content_store_uuid ON common_content');
        $this->addSql('ALTER TABLE common_content DROP store_uuid, DROP metadata');
    }
}
