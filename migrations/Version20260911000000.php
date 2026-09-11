<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add type discriminator to trade_product (normal vs coupon)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE trade_product ADD type VARCHAR(20) NOT NULL DEFAULT 'normal'");
        $this->addSql('CREATE INDEX idx_trade_product_type ON trade_product (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_trade_product_type ON trade_product');
        $this->addSql('ALTER TABLE trade_product DROP type');
    }
}
