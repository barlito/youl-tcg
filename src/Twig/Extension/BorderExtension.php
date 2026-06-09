<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Card;
use App\Service\BorderService;
use Twig\Attribute\AsTwigFunction;

class BorderExtension
{
    public function __construct(
        private readonly BorderService $borderService,
    ) {
    }

    /**
     * Generate inline CSS for card border.
     *
     * Usage in Twig:
     * <style>{{ card_border_css(card, 'my-card-id') }}</style>
     */
    #[AsTwigFunction(name: 'card_border_css', isSafe: ['html'])]
    public function getCardBorderCSS(Card $card, string $elementId = 'card-border'): string
    {
        return $this->borderService->generateInlineCSS($card, $elementId);
    }

    /**
     * Get resolved border configuration for a card.
     *
     * Usage in Twig:
     * {% set borderConfig = card_border_config(card) %}
     */
    #[AsTwigFunction(name: 'card_border_config')]
    public function getCardBorderConfig(Card $card): array
    {
        $config = $this->borderService->resolveBorderConfig($card);

        return $this->borderService->generateBorderStyles($config);
    }
}
