<?php

declare(strict_types=1);

$builder = require __DIR__ . '/vendor/barlito/utils/config/rector.php';

return $builder
    ->withPaths([__DIR__ . '/src'])
    ->withCache(__DIR__ . '/var/cache/rector')
    ->withRootFiles()
    ->withSkip([__DIR__ . '/.castor.stub.php']);
