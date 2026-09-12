<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename paste table to secret and align index names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paste RENAME TO secret');
        $this->addSql('DROP INDEX UNIQ_9C5678985F37A13B');
        $this->addSql('DROP INDEX idx_paste_expires_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_secret_token ON secret (token)');
        $this->addSql('CREATE INDEX idx_secret_expires_at ON secret (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_secret_token');
        $this->addSql('DROP INDEX idx_secret_expires_at');
        $this->addSql('ALTER TABLE secret RENAME TO paste');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C5678985F37A13B ON paste (token)');
        $this->addSql('CREATE INDEX idx_paste_expires_at ON paste (expires_at)');
    }
}
