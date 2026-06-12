<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ValidRarityRates extends Constraint
{
    public string $emptyMessage = 'A booster needs at least one slot (one weight map per card).';
    public string $invalidSlotMessage = 'Slot #{{ slot }} must be a non-empty map of rarity => weight.';
    public string $invalidRarityMessage = 'Slot #{{ slot }} uses unknown rarity "{{ rarity }}". Valid rarities: {{ rarities }}.';
    public string $invalidWeightMessage = 'Slot #{{ slot }}, rarity "{{ rarity }}": weight must be a positive integer.';
}
