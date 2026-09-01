<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Trade local Store directory projection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE trade_store_directory (
            id INT AUTO_INCREMENT NOT NULL,
            store_uuid VARCHAR(36) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL,
            source_updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_trade_store_directory_uuid (store_uuid),
            UNIQUE INDEX uniq_trade_store_directory_code (code),
            INDEX idx_trade_store_directory_code_status (code, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_store_directory');
    }
}
