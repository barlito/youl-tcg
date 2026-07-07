<?php

declare(strict_types=1);

namespace App\Twig;

use App\Dto\ResolvedCardVisual;
use App\Entity\Card;
use App\Service\Card\CardVisualResolver;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the resolved visuals of a card (cascade card -> extension ->
 * default) to templates, so CardComponent renders foil/mask/glow without
 * reaching into the entity itself.
 */
final readonly class CardVisualExtension
{
    public function __construct(
        private CardVisualResolver $cardVisualResolver,
    ) {
    }

    #[AsTwigFunction(name: 'card_visual')]
    public function cardVisual(Card $card): ResolvedCardVisual
    {
        return $this->cardVisualResolver->resolve($card);
    }
}
