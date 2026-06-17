<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Dto\ResolvedCardVisual;
use App\Entity\Card;
use App\Entity\Extension;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Resolves the effective visuals of a card through the cascade
 * card -> extension -> system default:
 *  - foil/mask textures: the card's own upload, else the extension's default;
 *  - glow / border / css class: the card's override field, else the
 *    extension's configuration, else null (the rarity-keyed default of
 *    holo.css takes over).
 */
final readonly class CardVisualResolver
{
    public function __construct(
        private UploaderHelper $uploaderHelper,
    ) {
    }

    public function resolve(Card $card): ResolvedCardVisual
    {
        $extension = $card->getExtension();
        $override = $card->getVisualConfigOverride();
        $config = $extension->getVisualConfig();

        return new ResolvedCardVisual(
            foilUrl: $this->foilUrl($card, $extension),
            maskUrl: $this->maskUrl($card, $extension),
            glow: $override->glow ?? $config->glow,
            borderColor: $override->borderColor ?? $config->borderColor,
            cssClass: $override->cssClass ?? $config->cssClass,
            holoIntensity: $override->holoIntensity ?? $config->holoIntensity,
            holoSaturation: $override->holoSaturation ?? $config->holoSaturation,
            holoGlitter: $override->holoGlitter ?? $config->holoGlitter,
        );
    }

    private function foilUrl(Card $card, Extension $extension): ?string
    {
        if (null !== $card->getImageFoilName()) {
            return $this->uploaderHelper->asset($card, 'imageFoilFile');
        }

        if (null !== $extension->getImageFoilName()) {
            return $this->uploaderHelper->asset($extension, 'imageFoilFile');
        }

        return null;
    }

    private function maskUrl(Card $card, Extension $extension): ?string
    {
        if (null !== $card->getImageMaskName()) {
            return $this->uploaderHelper->asset($card, 'imageMaskFile');
        }

        if (null !== $extension->getImageMaskName()) {
            return $this->uploaderHelper->asset($extension, 'imageMaskFile');
        }

        return null;
    }
}
