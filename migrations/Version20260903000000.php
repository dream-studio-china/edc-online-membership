<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OrderItem: replace specification_id FK with specification_uuid snapshot reference';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('trade_order_item');

        if (!$table->hasColumn('specification_uuid')) {
            $table->addColumn('specification_uuid', 'string', ['length' => 36, 'notnull' => false]);
        }
        if (!$table->hasIndex('idx_trade_order_item_spec_uuid')) {
            $table->addIndex(['specification_uuid'], 'idx_trade_order_item_spec_uuid');
        }

        // Backfill from existing FK (MySQL/PostgreSQL); for SQLite the same SQL works after column add
        $this->addSql('UPDATE trade_order_item oi SET specification_uuid = (SELECT s.uuid FROM trade_specification s WHERE s.id = oi.specification_id) WHERE oi.specification_id IS NOT NULL AND oi.specification_uuid IS NULL');

        // Drop FK and old column if exists
        if ($table->hasForeignKey('fk_trade_order_item_specification')) {
            $table->removeForeignKey('fk_trade_order_item_specification');
        } elseif ($table->hasForeignKey('FK_TRADE_ORDER_ITEM_SPECIFICATION')) {
            $table->removeForeignKey('FK_TRADE_ORDER_ITEM_SPECIFICATION');
        }
        // Doctrine may have generated index for specification_id
        if ($table->hasIndex('idx_trade_order_item_specification')) {
            $table->dropIndex('idx_trade_order_item_specification');
        }
        if ($table->hasIndex('IDX_TRADE_ORDER_ITEM_SPECIFICATION')) {
            $table->dropIndex('IDX_TRADE_ORDER_ITEM_SPECIFICATION');
        }
        if ($table->hasColumn('specification_id')) {
            $table->dropColumn('specification_id');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('trade_order_item');

        if (!$table->hasColumn('specification_id')) {
            $table->addColumn('specification_id', 'integer', ['notnull' => false]);
        }
        if (!$table->hasIndex('idx_trade_order_item_specification')) {
            $table->addIndex(['specification_id'], 'idx_trade_order_item_specification');
        }
        if (!$table->hasForeignKey('fk_trade_order_item_specification')) {
            $table->addForeignKeyConstraint('trade_specification', ['specification_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_trade_order_item_specification');
        }
        // Backfill from uuid where possible
        $this->addSql('UPDATE trade_order_item oi SET specification_id = (SELECT s.id FROM trade_specification s WHERE s.uuid = oi.specification_uuid) WHERE oi.specification_uuid IS NOT NULL AND oi.specification_id IS NULL');

        if ($table->hasColumn('specification_uuid')) {
            $table->dropColumn('specification_uuid');
        }
        if ($table->hasIndex('idx_trade_order_item_spec_uuid')) {
            $table->dropIndex('idx_trade_order_item_spec_uuid');
        }
    }
}
