<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create promotion_template and promotion tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE promotion_template (
            id INT AUTO_INCREMENT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            type VARCHAR(50) NOT NULL,
            phase INT DEFAULT 0 NOT NULL,
            enabled TINYINT(1) DEFAULT 0 NOT NULL,
            dsl LONGTEXT NOT NULL,
            fields JSON DEFAULT NULL,
            ast_cache JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_promotion_template_uuid (uuid),
            UNIQUE INDEX UNIQ_PROMOTION_TEMPLATE_NAME (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE promotion (
            id INT AUTO_INCREMENT NOT NULL,
            template_id INT NOT NULL,
            uuid VARCHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            store_code VARCHAR(50) NOT NULL,
            enabled TINYINT(1) DEFAULT 0 NOT NULL,
            start_time DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            end_time DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            config JSON DEFAULT NULL,
            conflict_mode VARCHAR(30) DEFAULT \'stackable\' NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_promotion_uuid (uuid),
            UNIQUE INDEX UNIQ_PROMOTION_NAME (name),
            INDEX IDX_PROMOTION_TEMPLATE (template_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE promotion_specifications (
            promotion_id INT NOT NULL,
            specification_id INT NOT NULL,
            INDEX IDX_PROMOTION_SPEC_PROMOTION (promotion_id),
            INDEX IDX_PROMOTION_SPEC_SPECIFICATION (specification_id),
            PRIMARY KEY(promotion_id, specification_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE promotion ADD CONSTRAINT FK_PROMOTION_TEMPLATE FOREIGN KEY (template_id) REFERENCES promotion_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_specifications ADD CONSTRAINT FK_PROMOTION_SPEC_PROMOTION FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_specifications ADD CONSTRAINT FK_PROMOTION_SPEC_SPECIFICATION FOREIGN KEY (specification_id) REFERENCES trade_specification (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotion_specifications DROP FOREIGN KEY FK_PROMOTION_SPEC_PROMOTION');
        $this->addSql('ALTER TABLE promotion_specifications DROP FOREIGN KEY FK_PROMOTION_SPEC_SPECIFICATION');
        $this->addSql('ALTER TABLE promotion DROP FOREIGN KEY FK_PROMOTION_TEMPLATE');
        $this->addSql('DROP TABLE promotion_specifications');
        $this->addSql('DROP TABLE promotion');
        $this->addSql('DROP TABLE promotion_template');
    }
}
