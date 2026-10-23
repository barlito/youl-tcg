<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Entity\CardRarityEnum;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the CardRarityEnum scale, labels and icons (1/1 crown included) to templates.
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

    /**
     * Rarity shape (● ◆ ★ ★★); an unknown value gets no icon.
     */
    #[AsTwigFunction(name: 'rarity_icon')]
    public function rarityIcon(CardRarityEnum | string $rarity): string
    {
        if ($rarity instanceof CardRarityEnum) {
            return $rarity->icon();
        }

        return CardRarityEnum::tryFrom($rarity)?->icon() ?? '';
    }

    /**
     * The 1/1 crown, tinted by `.unique-ic`; decorative: the caller writes « 1/1 » next to it.
     */
    #[AsTwigFunction(name: 'unique_icon', isSafe: ['html'])]
    public function uniqueIcon(): string
    {
        return '<span class="unique-ic" aria-hidden="true">' . CardRarityEnum::UNIQUE_ICON . '</span>';
    }
}
