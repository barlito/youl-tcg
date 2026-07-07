<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reduces the holo presets from 9 to 4 (kept: shine / basic / cosmos / trainer;
 * trainer kept over vstar — brighter, richer full-art recipe). Pure data
 * migration on the JSON visual configs (`Extension.visual_config` and
 * `Card.visual_config_override`, key `holoEffect`): every removed preset is
 * remapped to the closest kept one:
 *  - reverse -> basic   (plain rainbow sweep -> plain regular holo)
 *  - rainbow -> shine   (glitter + pastel wash -> amazing-rare glitter)
 *  - secret  -> shine   (gold glitter -> amazing-rare glitter)
 *  - vmax    -> trainer (foil + sunpillar full-art family)
 *  - vstar   -> trainer (same recipe family, trainer is the kept variant)
 *
 * Irreversible: the merge loses which preset a config originally carried.
 */
final class Version20260706120000 extends AbstractMigration
{
    private const array REMAP = [
        'reverse' => 'basic',
        'rainbow' => 'shine',
        'secret' => 'shine',
        'vmax' => 'trainer',
        'vstar' => 'trainer',
    ];

    public function getDescription(): string
    {
        return 'Reduce holo presets to 4 (shine/basic/cosmos/trainer): remap removed presets in the JSON visual configs';
    }

    public function up(Schema $schema): void
    {
        foreach (self::REMAP as $removed => $kept) {
            $this->addSql(
                <<<'SQL'
                    UPDATE extension
                    SET visual_config = jsonb_set(visual_config::jsonb, '{holoEffect}', to_jsonb(?::text))::json
                    WHERE visual_config->>'holoEffect' = ?
                    SQL,
                [$kept, $removed],
            );
            $this->addSql(
                <<<'SQL'
                    UPDATE card
                    SET visual_config_override = jsonb_set(visual_config_override::jsonb, '{holoEffect}', to_jsonb(?::text))::json
                    WHERE visual_config_override->>'holoEffect' = ?
                    SQL,
                [$kept, $removed],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration('Remapping the removed holo presets cannot be reversed.');
    }
}
