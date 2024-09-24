<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240731144515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER description TYPE TEXT');
        $this->addSql('ALTER TABLE discord_user ADD roles JSON NOT NULL DEFAULT \'["ROLE_USER"]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ALTER description TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE discord_user DROP roles');
    }
}
