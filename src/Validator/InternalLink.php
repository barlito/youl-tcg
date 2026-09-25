<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The value, when set, must be a link inside the app (see InternalLinkPolicy).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class InternalLink extends Constraint
{
    public string $message = 'Seuls les liens internes au site sont acceptés : un chemin comme « /boosters » ou une adresse du site lui-même.';
}
