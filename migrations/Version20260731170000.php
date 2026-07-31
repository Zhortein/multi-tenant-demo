<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260731170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Require globally unique login email addresses'; }
    public function up(Schema $schema): void { $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)'); }
    public function down(Schema $schema): void { $this->addSql('DROP INDEX UNIQ_1483A5E9E7927C74'); }
}
