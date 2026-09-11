<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911222408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE picture ADD file_name VARCHAR(255) DEFAULT NULL, CHANGE title title VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE restaurant DROP FOREIGN KEY `FK_EB95123FEA1C978`');
        $this->addSql('DROP INDEX uniq_eb95123fea1c978 ON restaurant');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB95123FCF60E67C ON restaurant (owner)');
        $this->addSql('ALTER TABLE restaurant ADD CONSTRAINT `FK_EB95123FEA1C978` FOREIGN KEY (owner) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE picture DROP file_name, CHANGE title title VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE restaurant DROP FOREIGN KEY FK_EB95123FCF60E67C');
        $this->addSql('DROP INDEX uniq_eb95123fcf60e67c ON restaurant');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB95123FEA1C978 ON restaurant (owner)');
        $this->addSql('ALTER TABLE restaurant ADD CONSTRAINT FK_EB95123FCF60E67C FOREIGN KEY (owner) REFERENCES user (id)');
    }
}
