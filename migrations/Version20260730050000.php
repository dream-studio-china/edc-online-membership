<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730050000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade_order ADD user_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE trade_order trade_order INNER JOIN users user ON user.id = trade_order.user_id SET trade_order.user_uuid = user.uuid');
        $this->addSql('CREATE INDEX idx_trade_order_user_uuid ON trade_order (user_uuid)');
        $this->addSql('ALTER TABLE trade_order DROP FOREIGN KEY FK_TRADE_ORDER_USER');
        $this->addSql('ALTER TABLE trade_order DROP user_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Trade order ownership is UUID-only.');
    }
}
