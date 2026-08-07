<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * card.extension_id was nullable in database while every card must belong to a
 * universe: only the Assert\NotBlank of the form layer enforced it, so any
 * write outside the admin (fixtures, command, future import) could create an
 * orphan card the front cannot attach anywhere.
 */
final class Version20260807114029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make card.extension_id NOT NULL (every card belongs to a universe)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE card ALTER extension_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE card ALTER extension_id DROP NOT NULL');
    }
}
