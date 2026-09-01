<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260730040000 extends AbstractMigration {
    public function getDescription(): string { return 'Remove Wallet legacy Identity user foreign key after owner UUID cutover'; }
    public function up(Schema $schema): void {
        $this->addSql('ALTER TABLE wallet DROP FOREIGN KEY fk_wallet_user');
        $this->addSql('DROP INDEX IDX_7C68921FA76ED395 ON wallet');
        $this->addSql('ALTER TABLE wallet DROP user_id');
        $this->addSql('ALTER TABLE wallet MODIFY owner_uuid VARCHAR(36) NOT NULL');
    }
    public function down(Schema $schema): void { throw new \RuntimeException('Wallet owner UUID cutover is irreversible.'); }
}
