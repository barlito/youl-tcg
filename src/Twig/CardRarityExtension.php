<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Entity\CardRarityEnum;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the CardRarityEnum scale and labels to templates.
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
     * Accepts raw strings (drop-rate keys come from JSON); an unknown value falls back to itself.
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
