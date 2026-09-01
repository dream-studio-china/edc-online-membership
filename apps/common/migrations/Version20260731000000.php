<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create final Common CMS and media storage schema baseline with UUID ownership';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE common_category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, parent_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, sort_order INTEGER NOT NULL DEFAULT 0, enabled BOOLEAN NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (parent_id) REFERENCES common_category (id) ON DELETE SET NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_637CDE56989D9B62 ON common_category (slug)');
            $this->addSql('CREATE INDEX IDX_637CDE56727ACA70 ON common_category (parent_id)');
            $this->addSql('CREATE TABLE common_tag (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_4B0904F9989D9B62 ON common_tag (slug)');
            $this->addSql('CREATE TABLE common_content (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category_id INTEGER DEFAULT NULL, title VARCHAR(255) NOT NULL, body CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX IDX_7EDB61F112469DE2 ON common_content (category_id)');
            $this->addSql('CREATE TABLE common_content_tag (content_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY(content_id, tag_id), FOREIGN KEY (content_id) REFERENCES common_content (id) ON DELETE CASCADE, FOREIGN KEY (tag_id) REFERENCES common_tag (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_EC5C3E4384A0A3ED ON common_content_tag (content_id)');
            $this->addSql('CREATE INDEX IDX_EC5C3E43BAD26311 ON common_content_tag (tag_id)');
            $this->addSql('CREATE TABLE common_media (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category_id INTEGER DEFAULT NULL, filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, size BIGINT NOT NULL, path VARCHAR(1024) NOT NULL, storage VARCHAR(20) NOT NULL DEFAULT \'local\', owner_uuid VARCHAR(36) DEFAULT NULL, alt VARCHAR(255) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, width INTEGER DEFAULT NULL, height INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX IDX_ED949AC612469DE2 ON common_media (category_id)');
            $this->addSql('CREATE TABLE common_page (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, body CLOB DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description CLOB DEFAULT NULL, status VARCHAR(50) NOT NULL DEFAULT \'draft\', published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_A492AEB1989D9B62 ON common_page (slug)');
            $this->addSql('CREATE TABLE common_comment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, parent_id INTEGER DEFAULT NULL, body CLOB NOT NULL, author_name VARCHAR(255) DEFAULT NULL, author_email VARCHAR(255) DEFAULT NULL, author_uuid VARCHAR(36) DEFAULT NULL, entity_type VARCHAR(255) NOT NULL, entity_id INTEGER NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'pending\', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (parent_id) REFERENCES common_comment (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_146A0334727ACA70 ON common_comment (parent_id)');
            $this->addSql('CREATE TABLE common_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `key` VARCHAR(255) NOT NULL, `value` CLOB DEFAULT NULL, type VARCHAR(50) NOT NULL DEFAULT \'string\', group_name VARCHAR(255) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, sort_order INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1F6AE9C04E645A7E ON common_setting (`key`)');
            $this->addSql('CREATE TABLE common_picture (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category_id INTEGER NOT NULL, owner_uuid VARCHAR(36) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, image VARCHAR(1024) NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_96C51ED112469DE2 ON common_picture (category_id)');

            return;
        }

        $this->addSql("CREATE TABLE common_category (id INT AUTO_INCREMENT NOT NULL, parent_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, sort_order INT NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_637CDE56989D9B62 (slug), INDEX IDX_637CDE56727ACA70 (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE common_category ADD CONSTRAINT fk_common_category_parent FOREIGN KEY (parent_id) REFERENCES common_category (id) ON DELETE SET NULL');
        $this->addSql("CREATE TABLE common_tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_4B0904F9989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE common_content (id INT AUTO_INCREMENT NOT NULL, category_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_7EDB61F112469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE common_content ADD CONSTRAINT fk_common_content_category FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE SET NULL');
        $this->addSql('CREATE TABLE common_content_tag (content_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_EC5C3E4384A0A3ED (content_id), INDEX IDX_EC5C3E43BAD26311 (tag_id), PRIMARY KEY(content_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE common_content_tag ADD CONSTRAINT fk_common_content_tag_content FOREIGN KEY (content_id) REFERENCES common_content (id) ON DELETE CASCADE, ADD CONSTRAINT fk_common_content_tag_tag FOREIGN KEY (tag_id) REFERENCES common_tag (id) ON DELETE CASCADE');
        $this->addSql("CREATE TABLE common_media (id INT AUTO_INCREMENT NOT NULL, category_id INT DEFAULT NULL, filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, size BIGINT NOT NULL, path VARCHAR(1024) NOT NULL, storage VARCHAR(20) NOT NULL DEFAULT 'local', owner_uuid VARCHAR(36) DEFAULT NULL, alt VARCHAR(255) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_ED949AC612469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE common_media ADD CONSTRAINT fk_common_media_category FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE SET NULL');
        $this->addSql("CREATE TABLE common_page (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, body LONGTEXT DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL DEFAULT 'draft', published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A492AEB1989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE common_comment (id INT AUTO_INCREMENT NOT NULL, parent_id INT DEFAULT NULL, body LONGTEXT NOT NULL, author_name VARCHAR(255) DEFAULT NULL, author_email VARCHAR(255) DEFAULT NULL, author_uuid VARCHAR(36) DEFAULT NULL, entity_type VARCHAR(255) NOT NULL, entity_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_146A0334727ACA70 (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE common_comment ADD CONSTRAINT fk_common_comment_parent FOREIGN KEY (parent_id) REFERENCES common_comment (id) ON DELETE CASCADE');
        $this->addSql("CREATE TABLE common_setting (id INT AUTO_INCREMENT NOT NULL, `key` VARCHAR(255) NOT NULL, `value` LONGTEXT DEFAULT NULL, type VARCHAR(50) NOT NULL DEFAULT 'string', group_name VARCHAR(255) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1F6AE9C04E645A7E (`key`), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE common_picture (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, owner_uuid VARCHAR(36) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, image VARCHAR(1024) NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_96C51ED112469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE common_picture ADD CONSTRAINT fk_common_picture_category FOREIGN KEY (category_id) REFERENCES common_category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE common_picture');
        $this->addSql('DROP TABLE common_setting');
        $this->addSql('DROP TABLE common_comment');
        $this->addSql('DROP TABLE common_page');
        $this->addSql('DROP TABLE common_media');
        $this->addSql('DROP TABLE common_content_tag');
        $this->addSql('DROP TABLE common_content');
        $this->addSql('DROP TABLE common_tag');
        $this->addSql('DROP TABLE common_category');
    }
}
