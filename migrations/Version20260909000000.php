<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create encrypted paste storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE paste (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token VARCHAR(64) NOT NULL, hint VARCHAR(255) DEFAULT NULL, salt BLOB NOT NULL, nonce BLOB NOT NULL, ciphertext BLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C5678985F37A13B ON paste (token)');
        $this->addSql('CREATE INDEX idx_paste_expires_at ON paste (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE paste');
    }
}
