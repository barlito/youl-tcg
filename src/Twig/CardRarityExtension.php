<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Entity\CardRarityEnum;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the rarity scale to templates so the ranking and the French labels
 * live in CardRarityEnum only (no more per-template label maps).
 */
final readonly class CardRarityExtension
{
    /**
     * @return list<CardRarityEnum> rarities ordered from least to most rare
     */
    #[AsTwigFunction(name: 'card_rarities')]
    public function cardRarities(): array
    {
        return CardRarityEnum::ascending();
    }

    /**
     * Accepts raw strings too (drop-rate keys come from JSON); an unknown
     * value falls back to itself, like the old per-template |default(rarity).
     */
    #[AsTwigFunction(name: 'rarity_label')]
    public function rarityLabel(CardRarityEnum | string $rarity): string
    {
        if ($rarity instanceof CardRarityEnum) {
            return $rarity->label();
        }

        return CardRarityEnum::tryFrom($rarity)?->label() ?? $rarity;
    }
}
