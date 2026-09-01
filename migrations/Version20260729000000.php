<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable correlation and causation metadata to integration Outboxes';
    }

    public function up(Schema $schema): void
    {
        foreach (['trade_outbox_message', 'store_outbox_message', 'inventory_outbox_message'] as $table) {
            $this->addNullableColumn($table, 'correlation_id');
            $this->addNullableColumn($table, 'causation_id');
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['trade_outbox_message', 'store_outbox_message', 'inventory_outbox_message'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP correlation_id', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP causation_id', $table));
        }
    }

    private function addNullableColumn(string $table, string $column): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s VARCHAR(36) NULL, ALGORITHM=INSTANT', $table, $column));

            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD %s VARCHAR(36) DEFAULT NULL', $table, $column));
    }
}
