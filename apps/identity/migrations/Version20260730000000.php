<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create final Identity and WeChat login-owned schema baseline';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(180) NOT NULL, phone VARCHAR(20) DEFAULT NULL, phone_verified BOOLEAN NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL, roles JSON NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_users_uuid ON users (uuid)');
            $this->addSql('CREATE UNIQUE INDEX uniq_users_username ON users (username)');
            $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');
            $this->addSql('CREATE UNIQUE INDEX uniq_users_phone ON users (phone)');
            $this->addSql('CREATE TABLE identity_refresh_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, refresh_token_hash VARCHAR(128) NOT NULL, jti VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, replaced_by_token_id INTEGER DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent CLOB DEFAULT NULL, FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_refresh_token_hash ON identity_refresh_token (refresh_token_hash)');
            $this->addSql('CREATE INDEX idx_refresh_token_user ON identity_refresh_token (user_id)');
            $this->addSql("CREATE TABLE identity_profile (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, uuid VARCHAR(36) NOT NULL, level VARCHAR(30) NOT NULL DEFAULT 'bronze', nickname VARCHAR(255) DEFAULT NULL, avatar VARCHAR(500) DEFAULT NULL, metadata JSON DEFAULT NULL, joined_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)");
            $this->addSql('CREATE UNIQUE INDEX uniq_identity_profile_uuid ON identity_profile (uuid)');
            $this->addSql('CREATE UNIQUE INDEX uniq_identity_profile_user_id ON identity_profile (user_id)');
            $this->addSql('CREATE TABLE wechat_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_uuid VARCHAR(36) NOT NULL, openid VARCHAR(64) NOT NULL, unionid VARCHAR(64) DEFAULT NULL, session_key VARCHAR(64) DEFAULT NULL, nickname VARCHAR(128) DEFAULT NULL, avatar VARCHAR(512) DEFAULT NULL, sex INTEGER DEFAULT NULL, province VARCHAR(64) DEFAULT NULL, city VARCHAR(64) DEFAULT NULL, country VARCHAR(64) DEFAULT NULL, app_type VARCHAR(20) NOT NULL, raw_data JSON DEFAULT NULL, last_login_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_openid ON wechat_user (openid)');
            $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_user_uuid ON wechat_user (user_uuid)');

            return;
        }

        $this->addSql("CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, username VARCHAR(180) NOT NULL, phone VARCHAR(20) DEFAULT NULL, phone_verified TINYINT(1) NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, UNIQUE INDEX uniq_users_uuid (uuid), UNIQUE INDEX uniq_users_username (username), UNIQUE INDEX uniq_users_email (email), UNIQUE INDEX uniq_users_phone (phone), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE identity_refresh_token (id BIGINT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, refresh_token_hash VARCHAR(128) NOT NULL, jti VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', replaced_by_token_id BIGINT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, INDEX idx_refresh_token_hash (refresh_token_hash), INDEX idx_refresh_token_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE identity_refresh_token ADD CONSTRAINT fk_refresh_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql("CREATE TABLE identity_profile (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, uuid VARCHAR(36) NOT NULL, level VARCHAR(30) NOT NULL DEFAULT 'bronze', nickname VARCHAR(255) DEFAULT NULL, avatar VARCHAR(500) DEFAULT NULL, metadata JSON DEFAULT NULL, joined_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_identity_profile_uuid (uuid), UNIQUE INDEX uniq_identity_profile_user_id (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql('ALTER TABLE identity_profile ADD CONSTRAINT fk_identity_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql("CREATE TABLE wechat_user (id INT AUTO_INCREMENT NOT NULL, user_uuid VARCHAR(36) NOT NULL, openid VARCHAR(64) NOT NULL, unionid VARCHAR(64) DEFAULT NULL, session_key VARCHAR(64) DEFAULT NULL, nickname VARCHAR(128) DEFAULT NULL, avatar VARCHAR(512) DEFAULT NULL, sex INT DEFAULT NULL, province VARCHAR(64) DEFAULT NULL, city VARCHAR(64) DEFAULT NULL, country VARCHAR(64) DEFAULT NULL, app_type VARCHAR(20) NOT NULL, raw_data JSON DEFAULT NULL, last_login_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_wechat_user_openid (openid), UNIQUE INDEX uniq_wechat_user_user_uuid (user_uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wechat_user');
        $this->addSql('DROP TABLE identity_profile');
        $this->addSql('DROP TABLE identity_refresh_token');
        $this->addSql('DROP TABLE users');
    }
}
