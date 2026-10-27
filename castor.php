<?php

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\import;
use function Castor\io;
use function Castor\capture;
use function Castor\context;

#[AsContext(name: 'my_context', default: true)]
function my_context(): Context
{
    return new Context(environment: ['STACK_NAME' => 'ytcg']);
}

#[AsTask('generate-jwt-key-pair')]
function generateJwtKeyPair(): void
{
    $containerName = capture('docker ps --filter name="${STACK_NAME}_php" -q');

    $output = capture(
        'docker exec ' . $containerName . ' bash -c "bin/console lexik:jwt:generate-keypair --skip-if-exists"',
        context: context()->withQuiet()->withAllowFailure()
    );

    io()->info($output);
}

/**
 * Copy the packs.com placeholder pack model into public/models/ (served dir).
 *
 * The model lives in the gitignored design handoff and is NEVER committed; this
 * task makes it available locally so the 3D pack opening works in dev. Without
 * it the opening gracefully falls back to the 2D swipe pack.
 */
#[AsTask('sync-pack-model')]
function syncPackModel(): void
{
    $source = __DIR__ . '/.claude/design_handoff_youl/refs-packs/model';
    $target = __DIR__ . '/public/models';

    if (!is_dir($source)) {
        io()->warning('No placeholder model found at ' . $source . ' — skipping (the opening falls back to the 2D pack).');

        return;
    }

    if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
        io()->error('Could not create ' . $target);

        return;
    }

    foreach (['pack-wide.gltf', 'pack-wide.bin', 'opening.png', 'fallback.png'] as $file) {
        if (is_file($source . '/' . $file)) {
            copy($source . '/' . $file, $target . '/' . $file);
        }
    }

    io()->success('Placeholder pack model synced to public/models/.');
}

/**
 * Dev only: copy the images referenced by the dev fixtures (fixtures/dev-assets.json:
 * Vich mapping => published file name => path inside the YoulzAssets repo) into
 * public/uploads/. The repo is only read; its path comes from YOULZ_ASSETS_DIR
 * (env, .env.dev.local or .env.dev). Idempotent: an up-to-date copy is skipped.
 *
 *   castor dev:sync-assets [--force]
 */
#[AsTask('dev:sync-assets')]
function devSyncAssets(bool $force = false): void
{
    $assetsDir = youlzAssetsDir();
    if (null === $assetsDir || !is_dir($assetsDir . '/YTCG')) {
        io()->error('YoulzAssets introuvable (' . ($assetsDir ?? 'YOULZ_ASSETS_DIR non défini') . ') : clone github.com/barlito/youlz-assets et renseigne YOULZ_ASSETS_DIR dans .env.dev.local.');

        return;
    }

    /** @var array<string, array<string, string>> $map */
    $map = json_decode((string) file_get_contents(__DIR__ . '/fixtures/dev-assets.json'), true, flags: \JSON_THROW_ON_ERROR);
    $copied = $skipped = 0;
    $missing = [];
    foreach ($map as $mapping => $files) {
        $targetDir = __DIR__ . '/public/uploads/' . $mapping;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            io()->error('Could not create ' . $targetDir);

            return;
        }

        foreach ($files as $target => $source) {
            $from = $assetsDir . '/' . $source;
            $to = $targetDir . '/' . $target;
            if (!is_file($from)) {
                $missing[] = $source;
                continue;
            }

            if (!$force && is_file($to) && filesize($to) === filesize($from) && filemtime($to) >= filemtime($from)) {
                ++$skipped;
                continue;
            }

            copy($from, $to);
            ++$copied;
        }
    }

    foreach ($missing as $source) {
        io()->warning('absent de YoulzAssets : ' . $source);
    }

    io()->success(sprintf('%d image(s) copiée(s), %d déjà à jour, depuis %s.', $copied, $skipped, $assetsDir));
}

function youlzAssetsDir(): ?string
{
    $value = getenv('YOULZ_ASSETS_DIR') ?: null;
    foreach (['.env.dev.local', '.env.dev'] as $file) {
        if (null !== $value || !is_file(__DIR__ . '/' . $file)) {
            continue;
        }

        if (preg_match('/^YOULZ_ASSETS_DIR=(.*)$/m', (string) file_get_contents(__DIR__ . '/' . $file), $match)) {
            $value = trim($match[1], " \t'\"");
        }
    }

    if (null === $value || '' === $value) {
        return null;
    }

    $home = getenv('HOME') ?: '';

    return rtrim(str_replace(['${HOME}', '$HOME'], $home, preg_replace('#^~(?=/|$)#', $home, $value) ?? $value), '/');
}

import('make/castor_entrypoint.php');

/**
 * Batch holo masks for a whole extension: rembg every published card artwork
 * that has NO mask yet, drop the masks into public/uploads/masks/ and point
 * card.image_mask_name at them. Hand-made masks are never clobbered
 * (only image_mask_name IS NULL is touched — use --force to redo those too,
 * existing uploads still stay untouched).
 *
 *   castor holo:masks <extension-slug> [--variant background|subject] [--blur 1.5]
 */
#[AsTask('holo:masks')]
function holoMasks(
    string $slug,
    string $variant = 'background',
    float $blur = 1.5,
): void {
    if (!in_array($variant, ['background', 'subject'], true)) {
        io()->error('variant must be "background" (reverse holo, le perso reste net) or "subject"');

        return;
    }

    $php = capture('docker ps --filter name="${STACK_NAME}_php" -q');
    if ('' === trim($php)) {
        io()->error('PHP container not running (make docker.deploy)');

        return;
    }

    // cards of the extension, published, without a mask
    $sql = sprintf(
        "SELECT c.id || '|' || c.image_name FROM card c JOIN extension e ON e.id = c.extension_id"
        . " WHERE e.slug = '%s' AND c.status = 2 AND c.image_name IS NOT NULL AND c.image_mask_name IS NULL",
        str_replace("'", "''", $slug),
    );
    $rows = capture(
        'docker exec ' . $php . ' bin/console dbal:run-sql ' . escapeshellarg($sql),
        context: context()->withQuiet(),
    );
    preg_match_all('/([0-9a-f-]{36})\|(\S+\.(?:png|jpe?g|webp))/i', $rows, $matches, PREG_SET_ORDER);

    if ([] === $matches) {
        io()->success('Nothing to do: every published card of "' . $slug . '" already has a mask (or none found).');

        return;
    }

    $artworks = [];
    foreach ($matches as [, $id, $image]) {
        $path = __DIR__ . '/public/uploads/cards/' . $image;
        if (is_file($path)) {
            $artworks[$id] = $image;
        } else {
            io()->warning('artwork file missing, skipped: ' . $image);
        }
    }
    io()->info(sprintf('%d carte(s) à traiter (variant: %s)', count($artworks), $variant));

    // build the rembg image once, then batch every artwork in ONE run (the
    // u2net model load dominates the cost)
    capture('docker build -q -t ytcg-holo tools/holo', context: context()->withTimeout(600));
    $files = implode(' ', array_map(
        static fn (string $image): string => escapeshellarg('public/uploads/cards/' . $image),
        $artworks,
    ));
    $out = capture(
        'docker run --rm -v "$PWD:/work" ytcg-holo ' . $files
        . ' --out public/uploads/masks --only ' . $variant . ' --blur ' . $blur,
        context: context()->withTimeout(3600),
    );
    io()->write($out . PHP_EOL);

    // point each card at its generated mask
    $updated = 0;
    foreach ($artworks as $id => $image) {
        $mask = pathinfo($image, PATHINFO_FILENAME) . '-mask-' . $variant . '.png';
        if (!is_file(__DIR__ . '/public/uploads/masks/' . $mask)) {
            io()->warning('mask not produced for ' . $image);

            continue;
        }
        capture(
            'docker exec ' . $php . ' bin/console dbal:run-sql ' . escapeshellarg(sprintf(
                "UPDATE card SET image_mask_name = '%s' WHERE id = '%s' AND image_mask_name IS NULL",
                $mask,
                $id,
            )),
            context: context()->withQuiet(),
        );
        ++$updated;
    }

    io()->success(sprintf('%d mask(s) générés et branchés pour "%s".', $updated, $slug));
}
