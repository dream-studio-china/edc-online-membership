<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payment_deduction table for invoice wallet balance deductions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_deduction (
                id INT AUTO_INCREMENT NOT NULL,
                invoice_id INT NOT NULL,
                uuid VARCHAR(36) NOT NULL,
                type VARCHAR(30) NOT NULL,
                amount BIGINT NOT NULL,
                currency VARCHAR(10) NOT NULL,
                status VARCHAR(30) DEFAULT 'pending' NOT NULL,
                wallet_transaction_id VARCHAR(64) DEFAULT NULL,
                refund_transaction_id VARCHAR(64) DEFAULT NULL,
                reference_id VARCHAR(64) NOT NULL,
                metadata JSON DEFAULT NULL,
                created_at DATETIME NOT NULL,
                applied_at DATETIME DEFAULT NULL,
                released_at DATETIME DEFAULT NULL,
                refunded_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_payment_deduction_uuid (uuid),
                UNIQUE INDEX uniq_payment_deduction_reference (reference_id),
                UNIQUE INDEX uniq_payment_deduction_invoice_type (invoice_id, type),
                INDEX idx_payment_deduction_invoice_status (invoice_id, status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_deduction
                ADD CONSTRAINT fk_payment_deduction_invoice FOREIGN KEY (invoice_id)
                    REFERENCES payment_invoice (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_deduction DROP FOREIGN KEY fk_payment_deduction_invoice');
        $this->addSql('DROP TABLE payment_deduction');
    }
}
