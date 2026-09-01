<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace payment invoice identity association with payment-owned payer UUID directory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_payer_directory (id INT AUTO_INCREMENT NOT NULL, identity_user_id INT DEFAULT NULL, user_uuid VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_payment_payer_directory_identity_user_id (identity_user_id), UNIQUE INDEX uniq_payment_payer_directory_user_uuid (user_uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('INSERT INTO payment_payer_directory (identity_user_id, user_uuid) SELECT id, uuid FROM users');
        $this->addSql('ALTER TABLE payment_invoice ADD payer_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE payment_invoice invoice INNER JOIN users user ON user.id = invoice.payer_id SET invoice.payer_uuid = user.uuid WHERE invoice.payer_id IS NOT NULL');
        $this->addSql('ALTER TABLE payment_invoice DROP FOREIGN KEY FK_892C19AEC17AD9A9');
        $this->addSql('DROP INDEX IDX_892C19AEC17AD9A9 ON payment_invoice');
        $this->addSql('ALTER TABLE payment_invoice DROP payer_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_invoice ADD payer_id INT DEFAULT NULL');
        $this->addSql('UPDATE payment_invoice invoice INNER JOIN payment_payer_directory directory ON directory.user_uuid = invoice.payer_uuid SET invoice.payer_id = directory.identity_user_id WHERE directory.identity_user_id IS NOT NULL');
        $this->addSql('CREATE INDEX IDX_892C19AEC17AD9A9 ON payment_invoice (payer_id)');
        $this->addSql('ALTER TABLE payment_invoice ADD CONSTRAINT FK_892C19AEC17AD9A9 FOREIGN KEY (payer_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment_invoice DROP payer_uuid');
        $this->addSql('DROP TABLE payment_payer_directory');
    }
}
