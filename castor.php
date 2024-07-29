<?php

use Castor\Attribute\AsContext;
use Castor\Context;

use function Castor\import;

#[AsContext(name: 'my_context', default: true)]
function my_context(): Context
{
    return new Context(environment: ['STACK_NAME' => 'ytcg']);
}

import('make/castor_entrypoint.php');