<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250217203009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status to card and extension';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ADD status INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE discord_user ALTER roles DROP DEFAULT');
        $this->addSql('ALTER TABLE extension ADD status INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP status');
        $this->addSql('ALTER TABLE card DROP status');
        $this->addSql('ALTER TABLE discord_user ALTER roles SET DEFAULT \'["ROLE_USER"]\'');
    }
}
