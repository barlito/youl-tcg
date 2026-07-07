<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the extension-level default foil/mask uploads. A mask must match each
 * card's artwork (a set-wide default is always misaligned), and the shared
 * foil use case is already covered by the visual config's foilTexture
 * library select. Cascade becomes: card upload → library texture.
 */
final class Version20260707170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop extension.image_foil_name / image_mask_name (per-card only + library)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension DROP image_foil_name');
        $this->addSql('ALTER TABLE extension DROP image_mask_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE extension ADD image_foil_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE extension ADD image_mask_name VARCHAR(255) DEFAULT NULL');
    }
}
