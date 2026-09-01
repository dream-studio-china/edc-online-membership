<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730070000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wechat_user ADD user_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE wechat_user wechat_user INNER JOIN users user ON user.id = wechat_user.user_id SET wechat_user.user_uuid = user.uuid');
        $this->addSql('ALTER TABLE wechat_user MODIFY user_uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_wechat_user_user_uuid ON wechat_user (user_uuid)');
        $this->addSql('ALTER TABLE wechat_user DROP FOREIGN KEY FK_4656660EA76ED395');
        $this->addSql('ALTER TABLE wechat_user DROP INDEX uniq_wechat_user_user');
        $this->addSql('ALTER TABLE wechat_user DROP user_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Wechat user identity is UUID-only.');
    }
}
