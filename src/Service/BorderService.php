<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\BorderConfig\BorderConfigDTO;
use App\Entity\Card;

class BorderService
{
    /**
     * Resolve border configuration for a card.
     * Priority: Card override → Extension default → System default.
     */
    public function resolveBorderConfig(Card $card): BorderConfigDTO
    {
        // 1. Try card override
        if ($card->getBorderConfig() instanceof BorderConfigDTO) {
            return $card->getBorderConfig();
        }

        // 2. Try extension default
        if ($card->getExtension() && $card->getExtension()->getBorderConfig() instanceof BorderConfigDTO) {
            return $card->getExtension()->getBorderConfig();
        }

        // 3. Fallback to system default (simple gray border)
        return $this->getDefaultBorderConfig();
    }

    /**
     * Get default border configuration (simple gray border).
     */
    public function getDefaultBorderConfig(): BorderConfigDTO
    {
        $config = new BorderConfigDTO();
        $config->type = 'gradient';
        $config->colors = ['#6b7280', '#9ca3af']; // Gray gradient
        $config->width = 3;
        $config->radius = 12;
        $config->angle = 135;

        return $config;
    }

    /**
     * Generate CSS styles for a border configuration.
     */
    public function generateBorderStyles(BorderConfigDTO $config): array
    {
        $gradient = 'linear-gradient(' . $config->angle . 'deg, ' . implode(', ', $config->colors) . ')';

        $styles = [
            'gradient' => $gradient,
            'width' => $config->width,
            'radius' => $config->radius,
            'angle' => $config->angle,
        ];

        // Glow styles
        if ($config->glow && $config->glow->enabled) {
            $glowSize = ($config->glow->intensity / 100) * 60;
            $styles['glow'] = [
                'enabled' => true,
                'size' => $glowSize,
                'color' => $config->glow->color,
                'pulse' => $config->glow->pulse,
            ];
        }

        // Fade styles
        if ($config->fade && $config->fade->enabled) {
            $styles['fade'] = [
                'enabled' => true,
                'start' => $config->fade->start,
                'length' => $config->fade->length,
                'direction' => $config->fade->direction,
            ];
        }

        return $styles;
    }

    /**
     * Generate inline CSS string for applying border in template.
     */
    public function generateInlineCSS(Card $card, string $elementId = 'card-border'): string
    {
        $config = $this->resolveBorderConfig($card);
        $styles = $this->generateBorderStyles($config);

        $gradient = $styles['gradient'];
        $width = $styles['width'];
        $radius = $styles['radius'];

        $css = <<<CSS
            #{$elementId} {
                position: relative;
                display: inline-block;
            }

            #{$elementId}::before {
                content: '';
                position: absolute;
                top: -{$width}px;
                left: -{$width}px;
                right: -{$width}px;
                bottom: -{$width}px;
                background: {$gradient};
                border-radius: {$radius}px;
                z-index: -1;
CSS;

        // Add fade mask
        if (isset($styles['fade']) && $styles['fade']['enabled']) {
            $fadeStart = $styles['fade']['start'];
            $fadeEnd = min(100, $fadeStart + $styles['fade']['length']);
            $fadeDir = $styles['fade']['direction'];

            $maskGradient = "linear-gradient({$fadeDir}deg, black 0%, black {$fadeStart}%, transparent {$fadeEnd}%)";
            $css .= "\n                -webkit-mask-image: {$maskGradient};";
            $css .= "\n                mask-image: {$maskGradient};";
        }

        // Add glow
        if (isset($styles['glow']) && $styles['glow']['enabled']) {
            $glowSize = $styles['glow']['size'];
            $glowColor = $styles['glow']['color'];

            $boxShadow = '0 0 ' . ($glowSize * 0.5) . "px {$glowColor}, 0 0 {$glowSize}px {$glowColor}80";
            $css .= "\n                box-shadow: {$boxShadow};";

            if ($styles['glow']['pulse']) {
                $css .= "\n                animation: border-pulse 2s ease-in-out infinite;";
            }
        }

        $css .= "\n            }";

        // Add pulse animation if needed
        if (isset($styles['glow']) && $styles['glow']['enabled'] && $styles['glow']['pulse']) {
            $glowSize = $styles['glow']['size'];
            $glowColor = $styles['glow']['color'];

            $normalShadow = '0 0 ' . ($glowSize * 0.5) . "px {$glowColor}, 0 0 {$glowSize}px {$glowColor}80";
            $peakShadow = '0 0 ' . ($glowSize * 0.75) . "px {$glowColor}, 0 0 " . ($glowSize * 1.5) . "px {$glowColor}cc";

            $css .= <<<CSS


            @keyframes border-pulse {
                0%, 100% { box-shadow: {$normalShadow}; }
                50% { box-shadow: {$peakShadow}; }
            }
CSS;
        }

        return $css;
    }
}
