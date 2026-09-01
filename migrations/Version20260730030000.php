<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Wallet-owned owner UUID references alongside legacy Identity user foreign keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet ADD owner_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE wallet wallet INNER JOIN users user ON user.id = wallet.user_id SET wallet.owner_uuid = user.uuid');
        $this->addSql('CREATE INDEX idx_wallet_owner_uuid ON wallet (owner_uuid)');
        $this->addSql('CREATE UNIQUE INDEX uniq_wallet_owner_uuid_currency ON wallet (owner_uuid, currency)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_wallet_owner_uuid_currency ON wallet');
        $this->addSql('DROP INDEX idx_wallet_owner_uuid ON wallet');
        $this->addSql('ALTER TABLE wallet DROP owner_uuid');
    }
}
