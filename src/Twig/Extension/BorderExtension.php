<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Card;
use App\Service\BorderService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BorderExtension extends AbstractExtension
{
    public function __construct(
        private readonly BorderService $borderService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_border_css', [$this, 'getCardBorderCSS'], ['is_safe' => ['html']]),
            new TwigFunction('card_border_config', [$this, 'getCardBorderConfig']),
        ];
    }

    /**
     * Generate inline CSS for card border.
     *
     * Usage in Twig:
     * <style>{{ card_border_css(card, 'my-card-id') }}</style>
     */
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
    public function getCardBorderConfig(Card $card): array
    {
        $config = $this->borderService->resolveBorderConfig($card);

        return $this->borderService->generateBorderStyles($config);
    }
}
