<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ValidRarityRates extends Constraint
{
    public string $emptyMessage = 'A booster needs at least one slot (one entry per card).';
    public string $invalidSlotMessage = 'Slot #{{ slot }} must be an object with a non-empty "rarities" map and a "holoChance".';
    public string $invalidRaritiesMessage = 'Slot #{{ slot }} must have a non-empty "rarities" map of rarity => weight.';
    public string $invalidRarityMessage = 'Slot #{{ slot }} uses unknown rarity "{{ rarity }}". Valid rarities: {{ rarities }}.';
    public string $invalidWeightMessage = 'Slot #{{ slot }}, rarity "{{ rarity }}": weight must be a positive integer.';
    public string $invalidHoloChanceMessage = 'Slot #{{ slot }}: "holoChance" must be an integer between 0 and 100.';
    public string $invalidUniqueChanceMessage = 'Slot #{{ slot }}: "uniqueChance" must be an integer between 0 and {{ scale }}.';
}
