<?php

declare(strict_types=1);

/**
 * Rebuilds the dev fixtures from the card images sitting in public/uploads/cards.
 * Universes, names and rarities are deduced from the file names — see
 * docs/dev-fixtures.md. Run it again after dropping new images in:
 *   docker exec $(docker ps --filter name=ytcg_php -q) php bin/dev/generate-fixtures.php
 */

$root = \dirname(__DIR__, 2);
$cardsDir = $root . '/public/uploads/cards';

// universes, most specific rule first: the first matching keyword wins
$universes = [
    'cyberpunk' => [
        'name' => 'Cyberpunk 2077',
        'description' => "Night City, ses corpos, ses cyberpsychos et ses implants hors de prix. Un univers de néons et de mauvaises décisions.",
        'glow' => '#a435f0',
        'keywords' => ['cyberpunk', 'new_la', 'niveau_24', 'niveau_330', 'niveau_776', 'pegre', 'marche_noir', 'le_corpo', 'street_kid', 'bullet_ballet', 'la_chrome', 'joueuse_de_mort', 'cyberpsycho', 'replicant', 'inspecteur', 'ingenieur', 'appartement', 'centre_des_operations', 'gala_de_charite', 'bureau_de'],
    ],
    'kda' => [
        'name' => 'K/DA',
        'description' => "Le girls band virtuel le plus bruyant de la faille. Scène, chorégraphies et skins alternatifs.",
        'glow' => '#ff3db0',
        'keywords' => ['kda', 'ahri', 'akali', 'evelynn', 'kaisa', 'jinx'],
    ],
    'w40k' => [
        'name' => 'Warhammer 40K',
        'description' => "Dans un futur très lointain, il n'y a que la guerre. Légions, xenos et tempêtes warp.",
        'glow' => '#c0392b',
        'keywords' => ['legion', 'space_marine', 'ork_de', 'waaagh', 'tyranide', 'necron', 'eldar', 'empereur', 'monde_ruche', 'princeps', 'mechanicus', 'chaos', 'warp', 'tau', 'vaisseau_monde', 'hobgobelin'],
    ],
    'bleach' => [
        'name' => 'Bleach',
        'description' => "Shinigamis, divisions du Gotei et Hollows : la Soul Society au grand complet.",
        'glow' => '#38bdf8',
        'keywords' => ['division', 'hollow', 'zaraki', 'vice_capitaine'],
    ],
    'magic' => [
        'name' => 'Magic',
        'description' => "Épées divines, dragons célestes et enchantements : la haute fantasy du multivers.",
        'glow' => '#f59e0b',
        'keywords' => ['magic', 'epee_divine', 'epee_demoniaque', 'enchantement', 'chevalier_de_la_lumiere', 'dragon_celeste', 'drogh_dhurr', 'marchand_de_sable', 'ogre_des_sables', 'arcane_master', 'prince_de_glace'],
    ],
    'cosmos' => [
        'name' => 'Cosmos',
        'description' => "Colonies martiennes, spatio-gares et contrebandiers : la frontière la plus froide.",
        'glow' => '#22d3ee',
        'keywords' => ['martienne', 'spatio', 'vaisseau_de_fret', 'treon', 'helium', 'space_nomad', 'kosmonaut', 'contrebandier', 'corsair', 'mercenaire', 'transport_luxueux', 'delavega', 'gemelo', 'marchand_warny'],
    ],
    'dystopie' => [
        'name' => 'Dystopie',
        'description' => "L'Ordre Nouveau, ses officiers et ses héros de la nation. Obéir, produire, sourire.",
        'glow' => '#94a3b8',
        'keywords' => ['ordnung', 'dystopia', 'parti', 'union', 'nation', 'kaiser', 'offizier', 'judith', 'samaz', 'officier', 'commandant', 'escadron', 'paysan', 'robot'],
    ],
    'eldritch' => [
        'name' => 'Cultes Anciens',
        'description' => "Ahlototh, Zocnoth et les grimoires qu'il vaudrait mieux ne pas ouvrir.",
        'glow' => '#84cc16',
        'keywords' => ['ahlototh', 'zocnoth', 'xizta', 'cult', 'god_almighty', 'miroir_du_confins', 'the_eternal', 'the_beyonder', 'the_watcher', 'sightless'],
    ],
    'sot' => [
        'name' => 'Storm of Time',
        'description' => "Les orages temporels et leurs voyageurs, entre deux époques qui n'existent plus.",
        'glow' => '#8b5cf6',
        'keywords' => ['sot', 'storm_of_time'],
    ],
    'lotr' => [
        'name' => 'Terre du Milieu',
        'description' => "Une compagnie, un anneau et beaucoup trop de marche à pied.",
        'glow' => '#65a30d',
        'keywords' => ['lotr', '_lr'],
    ],
    'velirp' => [
        'name' => 'VeliRP',
        'description' => "Les personnages du RP maison, évolutions et variantes comprises.",
        'glow' => '#f97316',
        'keywords' => ['velirp', 'warnyx', 'barlitox'],
    ],
    'psyche' => [
        'name' => 'Psyché',
        'description' => "Rêves, sommeils et charmes : ce que l'esprit fabrique quand on le laisse seul.",
        'glow' => '#e879f9',
        'keywords' => ['psyche', 'sommeil', 'charme'],
    ],
    'origines' => [
        'name' => 'Origines',
        'description' => "Les portraits fondateurs : la bande au complet, avant les univers.",
        'glow' => '#a435f0',
        'keywords' => [],
    ],
];

// tokens dropped from a card name (universe markers, variant markers handled apart)
$markers = ['cyberpunk', 'kda', 'magic', 'sot', 'lotr', 'psyche', 'velirp', 'red_dead', 'reddead'];
$properNouns = ['barlito', 'barlitox', 'benj', 'farf', 'farph', 'faurph', 'veli', 'warny', 'warnyx', 'julian', 'julien', 'juju', 'bernard', 'babou', 'bebou', 'bibou', 'linette', 'lineth', 'judith', 'samaz', 'frieda', 'ludwig', 'ahri', 'akali', 'evelynn', 'kaisa', 'jinx', 'brex', 'billy', 'tedi', 'dibi', 'jewben', 'drogh', 'dhurr', 'ahlototh', 'zocnoth', 'xizta', 'zaraki', 'ledjo', 'weebou', 'hipo', 'nairy', 'julian', 'delavega', 'masuo', 'zuki', 'kenpachi', 'treon', 'eldia', 'rog', 'bone', 'judith'];

$files = array_values(array_filter(scandir($cardsDir), static function (string $file): bool {
    if (!preg_match('/\.(png|jpe?g|webp)$/i', $file)) {
        return false;
    }

    // site assets that landed in the card mapping, not cards
    return !preg_match('/^(default-card-|ytcg-logo-|ytcg-background-)/', $file);
}));
sort($files);

$masks = array_values(array_filter(scandir($root . '/public/uploads/masks'), static fn (string $f): bool => (bool) preg_match('/\.(png|webp)$/i', $f)));

$cards = [];
foreach ($files as $index => $file) {
    $slug = preg_replace('/\.[a-z0-9]+$/i', '', $file);
    $tokens = explode('_', $slug);

    $universeKey = 'origines';
    foreach ($universes as $key => $universe) {
        foreach ($universe['keywords'] as $keyword) {
            if (str_contains($slug, $keyword)) {
                $universeKey = $key;
                break 2;
            }
        }
    }

    $isAlt = \in_array('alt', $tokens, true);
    $isEvo = \in_array('evo', $tokens, true);

    // name: drop the universe/variant markers, keep the rest readable
    $words = array_values(array_filter($tokens, static fn (string $token): bool => !\in_array($token, [...$markers, 'alt', 'evo', 'x'], true)));
    $name = trim(implode(' ', $words));
    // a file named after its universe only ("1_cyberpunk.png") leaves nothing readable
    if ('' === $name || 1 === preg_match('/^\d+$/', $name)) {
        $name = trim($universes[$universeKey]['name'] . ' ' . $name);
    }
    $name = ucfirst($name);
    foreach ($properNouns as $noun) {
        $name = preg_replace('/\b' . preg_quote($noun, '/') . '\b/i', ucfirst($noun), $name);
    }
    if ($isEvo) {
        $name .= ' (Évolution)';
    }
    if ($isAlt) {
        $name .= ' (Alt)';
    }

    $rarity = match (true) {
        str_contains($slug, 'empereur') || str_contains($slug, 'god_almighty') || str_contains($slug, 'god_killer') => 'legendary',
        $isAlt || $isEvo => 'rare',
        str_contains($slug, '_the_') || str_contains($slug, '_le_') || str_contains($slug, '_la_') || str_contains($slug, '_de_la_') => 'uncommon',
        default => 'common',
    };

    $maskName = null;
    foreach ($masks as $mask) {
        if (str_starts_with($mask, $slug . '-mask')) {
            $maskName = $mask;
            break;
        }
    }

    $cards[] = [
        'ref' => 'card_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($slug)),
        'name' => $name,
        'universe' => $universeKey,
        'rarity' => $rarity,
        'imageName' => $file,
        'maskName' => $maskName,
        'index' => $index,
    ];
}

// only keep universes that actually got cards
$used = array_unique(array_column($cards, 'universe'));
$universes = array_filter($universes, static fn (string $key): bool => \in_array($key, $used, true), \ARRAY_FILTER_USE_KEY);

// only the dev set is generated: fixtures/test stays hand-written and stable
$out = static fn (string $file, string $content): bool|int => file_put_contents($root . '/fixtures/dev/' . $file, $content);

// ------------------------------------------------------------------ Extension
// the smallest universe stays a draft and carries the "next universe" teaser
$counts = array_count_values(array_column($cards, 'universe'));
asort($counts);
$upcomingKey = array_key_first($counts);

$yaml = "# Généré depuis public/uploads/cards (voir docs/dev-fixtures.md).\n";
$yaml .= "App\\Entity\\Extension:\n";
foreach ($universes as $key => $universe) {
    $draft = $key === $upcomingKey;
    $yaml .= sprintf("    extension_%s:\n", $key);
    $yaml .= sprintf("        name: '%s'\n", str_replace("'", "''", $universe['name']));
    $yaml .= sprintf("        description: '%s'\n", str_replace("'", "''", $universe['description']));
    $yaml .= sprintf("        status: %d\n", $draft ? 1 : 2);
    if ($draft) {
        $yaml .= "        upcoming: true\n";
    }
    $yaml .= sprintf("        visualConfigJson: '{\"glow\": \"%s\"}'\n", $universe['glow']);
}
$out('Extension.yaml', $yaml);

// ----------------------------------------------------------------------- Card
// three 1/1: one held by the collector, one by Barlito, one still up for grabs
$uniqueRefs = [];
foreach ($cards as $card) {
    if ('legendary' === $card['rarity'] && \count($uniqueRefs) < 3) {
        $uniqueRefs[] = $card['ref'];
    }
}

$yaml = "# Généré depuis public/uploads/cards (voir docs/dev-fixtures.md).\n";
$yaml .= "App\\Entity\\Card:\n";
foreach ($cards as $card) {
    $yaml .= sprintf("    %s:\n", $card['ref']);
    $yaml .= sprintf("        name: '%s'\n", str_replace("'", "''", $card['name']));
    $yaml .= sprintf("        extension: '@extension_%s'\n", $card['universe']);
    $yaml .= sprintf("        description: '%s'\n", str_replace("'", "''", sprintf('%s, univers %s.', $card['name'], $universes[$card['universe']]['name'])));
    $yaml .= "        status: 2\n";
    $yaml .= sprintf("        rarity: '%s'\n", $card['rarity']);
    $yaml .= sprintf("        imageName: '%s'\n", $card['imageName']);
    if (null !== $card['maskName']) {
        $yaml .= sprintf("        imageMaskName: '%s'\n", $card['maskName']);
    }
    if (\in_array($card['ref'], $uniqueRefs, true)) {
        $yaml .= "        unique: true\n";
        $yaml .= "        alwaysHolo: true\n";
        if ($uniqueRefs[0] === $card['ref']) {
            $yaml .= "        claimedBy: '@discord_user_collectionneur'\n";
        }
        if (isset($uniqueRefs[1]) && $uniqueRefs[1] === $card['ref']) {
            $yaml .= "        claimedBy: '@discord_user_barlito'\n";
        }
    }
}
$out('Card.yaml', $yaml);

// -------------------------------------------------------------------- Booster
$boosterImages = ['booster-cyberpunk-6a500e183f587285639600.png', 'ytcg-6a4c4983731de915788991.png'];
$yaml = "# Un booster par univers publié. Le dernier n'est pas réclamable :\n";
$yaml .= "# il se distribue par code ou par récompense de streak.\n";
$yaml .= "App\\Entity\\Booster:\n";
$position = 0;
foreach ($universes as $key => $universe) {
    if ($key === $upcomingKey) {
        continue;
    }
    $claimable = $position < \count($universes) - 2;
    $yaml .= sprintf("    booster_%s:\n", $key);
    $yaml .= sprintf("        name: 'Pack %s'\n", str_replace("'", "''", $universe['name']));
    $yaml .= sprintf("        extension: '@extension_%s'\n", $key);
    $yaml .= sprintf("        imageName: '%s'\n", $boosterImages[$position % \count($boosterImages)]);
    if (!$claimable) {
        $yaml .= "        claimable: false\n";
    }
    $yaml .= "        rarityRates:\n";
    $yaml .= "            - { rarities: { common: 100 }, holoChance: 5 }\n";
    $yaml .= "            - { rarities: { common: 100 }, holoChance: 5 }\n";
    $yaml .= "            - { rarities: { common: 70, uncommon: 30 }, holoChance: 15 }\n";
    $yaml .= "            - { rarities: { uncommon: 70, rare: 30 }, holoChance: 30 }\n";
    $yaml .= "            - { rarities: { rare: 80, legendary: 20 }, holoChance: 60 }\n";
    ++$position;
}
$out('Booster.yaml', $yaml);

// ------------------------------------------------------------------- UserCard
// Répartition déterministe : Barlito et le Collectionneur se recouvrent en
// partie, les autres joueurs prennent des tranches plus fines. Objectif : les
// cinq états de la grille comparée visibles sur le profil du Collectionneur.
$owners = [
    'discord_user_barlito' => static fn (int $i): bool => 0 !== $i % 4,
    'discord_user_collectionneur' => static fn (int $i): bool => 0 === $i % 3 || 0 === $i % 5,
    'discord_user_juju' => static fn (int $i): bool => 0 === $i % 7,
    'discord_user_farph' => static fn (int $i): bool => 0 === $i % 8,
    'discord_user_benj' => static fn (int $i): bool => 0 === $i % 9,
    'discord_user_weeby' => static fn (int $i): bool => 0 === $i % 11,
    'discord_user_veli' => static fn (int $i): bool => 0 === $i % 13,
];

$yaml = "# Collections de démo, réparties par index de carte (aucun aléatoire) :\n";
$yaml .= "# sur le profil du Collectionneur, Barlito retrouve les cinq états de la\n";
$yaml .= "# grille comparée — en commun, seulement lui, seulement toi, manquante aux\n";
$yaml .= "# deux, et la 1/1 mystère.\n";
$yaml .= "App\\Entity\\UserCard:\n";
foreach ($cards as $card) {
    foreach ($owners as $owner => $rule) {
        $index = $card['index'];
        $claimed = \in_array($card['ref'], $uniqueRefs, true);
        if ($claimed) {
            // a 1/1 only ever belongs to whoever claimed it
            $isHolder = ($uniqueRefs[0] === $card['ref'] && 'discord_user_collectionneur' === $owner)
                || (isset($uniqueRefs[1]) && $uniqueRefs[1] === $card['ref'] && 'discord_user_barlito' === $owner);
            if (!$isHolder) {
                continue;
            }
            $quantity = 1;
            $holo = 1;
        } else {
            if (!$rule($index)) {
                continue;
            }
            $quantity = 1 + ($index % 4);
            $holo = 0 === $index % 9 ? 1 : 0;
        }

        $yaml .= sprintf("    user_card_%s_%s:\n", str_replace('discord_user_', '', $owner), substr($card['ref'], 5));
        $yaml .= sprintf("        discordUser: '@%s'\n", $owner);
        $yaml .= sprintf("        card: '@%s'\n", $card['ref']);
        $yaml .= sprintf("        quantity: %d\n", $quantity);
        if ($holo > 0) {
            $yaml .= sprintf("        holoQuantity: %d\n", $holo);
        }
    }
}
$out('UserCard.yaml', $yaml);

// ----------------------------------------------------------------- UserBooster
$yaml = "App\\Entity\\UserBooster:\n";
$position = 0;
foreach ($universes as $key => $universe) {
    if ($key === $upcomingKey) {
        continue;
    }
    if ($position < 3) {
        $yaml .= sprintf("    user_booster_barlito_%s:\n", $key);
        $yaml .= sprintf("        discordUser: '@discord_user_barlito'\n");
        $yaml .= sprintf("        booster: '@booster_%s'\n", $key);
        $yaml .= sprintf("        quantity: %d\n", 3 - $position);
    }
    ++$position;
}
$out('UserBooster.yaml', $yaml);

// -------------------------------------------------------------------- Banners
$banners = array_values(array_filter(scandir($root . '/public/uploads/banners'), static fn (string $f): bool => (bool) preg_match('/\.(png|jpe?g|webp)$/i', $f)));
$firstUniverse = array_key_first($universes);
$yaml = "App\\Entity\\ExtensionBanner:\n";
foreach ($banners as $position => $banner) {
    $yaml .= sprintf("    extension_banner_%d:\n", $position + 1);
    $yaml .= sprintf("        extension: '@extension_%s'\n", $firstUniverse);
    $yaml .= sprintf("        imageName: '%s'\n", $banner);
    $yaml .= sprintf("        position: %d\n", $position);
}
$out('ExtensionBanner.yaml', $yaml);

printf("%d cartes, %d univers (%s en brouillon/teaser), %d bannières\n", \count($cards), \count($universes), $universes[$upcomingKey]['name'], \count($banners));
foreach ($counts as $key => $count) {
    printf("  %-12s %3d cartes\n", $key, $count);
}
