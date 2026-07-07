<?php

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

import('make/castor_entrypoint.php');
