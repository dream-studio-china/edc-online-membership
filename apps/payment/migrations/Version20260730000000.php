<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create final Payment-owned schema baseline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE payment_invoice (
            id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL,
            out_trade_no VARCHAR(64) NOT NULL, transaction_id VARCHAR(128) DEFAULT NULL,
            source_type VARCHAR(50) NOT NULL, source_id VARCHAR(64) NOT NULL,
            scene VARCHAR(50) NOT NULL, payment VARCHAR(50) DEFAULT NULL,
            gateway VARCHAR(50) DEFAULT NULL, trade_type VARCHAR(50) DEFAULT NULL,
            status VARCHAR(30) DEFAULT 'pending' NOT NULL,
            amount BIGINT DEFAULT 0 NOT NULL, refunded_amount BIGINT DEFAULT 0 NOT NULL,
            currency VARCHAR(10) DEFAULT 'CNY' NOT NULL, payer_uuid VARCHAR(36) DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL,
            extra_data JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            refunded_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_payment_invoice_uuid (uuid),
            UNIQUE INDEX uniq_payment_invoice_out_trade_no (out_trade_no),
            INDEX idx_payment_invoice_source_status (source_type, source_id, status),
            INDEX idx_payment_invoice_source_scene (source_type, source_id, scene),
            INDEX idx_payment_invoice_payment_transaction (payment, transaction_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE payment_outbox_message (
            id BIGINT AUTO_INCREMENT NOT NULL, event_id VARCHAR(36) NOT NULL,
            correlation_id VARCHAR(36) DEFAULT NULL, causation_id VARCHAR(36) DEFAULT NULL,
            topic VARCHAR(120) NOT NULL, aggregate_type VARCHAR(80) NOT NULL, aggregate_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL, occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', attempts INT NOT NULL,
            last_error LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_payment_outbox_event_id (event_id),
            INDEX idx_payment_outbox_unpublished_available (published_at, available_at), PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");

        $this->addSql("CREATE TABLE payment_payer_directory (
            id INT AUTO_INCREMENT NOT NULL, identity_user_id INT DEFAULT NULL,
            user_uuid VARCHAR(36) NOT NULL,
            UNIQUE INDEX uniq_payment_payer_directory_identity_user_id (identity_user_id),
            UNIQUE INDEX uniq_payment_payer_directory_user_uuid (user_uuid), PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_payer_directory');
        $this->addSql('DROP TABLE payment_outbox_message');
        $this->addSql('DROP TABLE payment_invoice');
    }
}
