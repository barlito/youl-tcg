<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the per-rarity holo tuning knobs (`holoIntensity`, `holoSaturation`,
 * `holoGlitter`) from the JSON visual configs (`Extension.visual_config` and
 * `Card.visual_config_override`): the per-rarity holo recipes they drove were
 * removed — holo rendering is presets-only now (a card drawn holo without a
 * preset falls back to holo--basic in the template).
 *
 * Irreversible: the knob values are discarded.
 */
final class Version20260707100000 extends AbstractMigration
{
    private const array DROPPED_KEYS = ['holoIntensity', 'holoSaturation', 'holoGlitter'];

    public function getDescription(): string
    {
        return 'Drop the holo tuning knobs from the JSON visual configs (per-rarity holo recipes removed)';
    }

    public function up(Schema $schema): void
    {
        // jsonb_exists() instead of the jsonb `?` operator: PDO would parse the
        // operator as a positional placeholder.
        foreach (self::DROPPED_KEYS as $key) {
            $this->addSql(
                <<<'SQL'
                    UPDATE extension
                    SET visual_config = (visual_config::jsonb - :key::text)::json
                    WHERE jsonb_exists(visual_config::jsonb, :key::text)
                    SQL,
                ['key' => $key],
            );
            $this->addSql(
                <<<'SQL'
                    UPDATE card
                    SET visual_config_override = (visual_config_override::jsonb - :key::text)::json
                    WHERE jsonb_exists(visual_config_override::jsonb, :key::text)
                    SQL,
                ['key' => $key],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The dropped holo knob values cannot be restored.');
    }
}
