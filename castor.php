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

import('make/castor_entrypoint.php');
