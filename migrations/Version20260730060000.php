<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730060000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE common_media ADD owner_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE common_media media INNER JOIN users user ON user.id = media.user_id SET media.owner_uuid = user.uuid');
        $this->addSql('CREATE INDEX idx_common_media_owner_uuid ON common_media (owner_uuid)');
        $this->addSql('ALTER TABLE common_media DROP FOREIGN KEY fk_common_media_user');
        $this->addSql('ALTER TABLE common_media DROP user_id');
        $this->addSql('ALTER TABLE common_picture ADD owner_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE common_picture picture INNER JOIN users user ON user.id = picture.user_id SET picture.owner_uuid = user.uuid');
        $this->addSql('CREATE INDEX idx_common_picture_owner_uuid ON common_picture (owner_uuid)');
        $this->addSql('ALTER TABLE common_picture DROP FOREIGN KEY FK_COMMON_PICTURE_USER');
        $this->addSql('ALTER TABLE common_picture DROP user_id');
        $this->addSql('ALTER TABLE common_comment ADD author_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE common_comment comment INNER JOIN users user ON user.id = comment.author_id SET comment.author_uuid = user.uuid');
        $this->addSql('CREATE INDEX idx_common_comment_author_uuid ON common_comment (author_uuid)');
        $this->addSql('ALTER TABLE common_comment DROP FOREIGN KEY fk_comment_author');
        $this->addSql('ALTER TABLE common_comment DROP author_id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Common ownership is UUID-only.');
    }
}
