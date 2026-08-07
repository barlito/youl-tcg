<?php

declare(strict_types=1);

namespace App\Service\Card;

use App\Dto\ResolvedCardVisual;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardFrameEnum;
use Vich\UploaderBundle\Templating\Helper\UploaderHelper;

/**
 * Resolves the effective visuals of a card through the cascade
 * card -> extension -> system default:
 *  - mask: the card's own upload only (a mask must match the artwork,
 *    a set-wide default cannot);
 *  - foil: the card's own upload, else the library texture picked by the
 *    visual config (card override, else extension);
 *  - glow / border / css class / holo preset: the card's override field, else
 *    the extension's configuration, else null (rarity glow only; a card
 *    rendered holo without any preset falls back to holo--basic in
 *    CardComponent.html.twig);
 *  - frame: same cascade, but the system default is YOUL (CSS frames are on
 *    by default) — a set with frames baked in its artworks opts out with NONE.
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

        if (!$extension instanceof Extension) {
            throw new \LogicException(\sprintf('Card "%s" has no extension: only persisted cards can be visually resolved.', $card->getName()));
        }
        $override = $card->getVisualConfigOverride();
        $config = $extension->getVisualConfig();

        return new ResolvedCardVisual(
            foilUrl: $this->foilUrl($card, $extension),
            maskUrl: $this->maskUrl($card),
            glow: $override->glow ?? $config->glow,
            borderColor: $override->borderColor ?? $config->borderColor,
            cssClass: $override->cssClass ?? $config->cssClass,
            holoEffect: $override->holoEffect ?? $config->holoEffect,
            foilSize: $override->foilSize ?? $config->foilSize,
            frame: $override->frame ?? $config->frame ?? CardFrameEnum::YOUL,
            nameFont: $override->nameFont ?? $config->nameFont,
            frameLineStart: $override->frameLineStart ?? $config->frameLineStart,
            frameLineEnd: $override->frameLineEnd ?? $config->frameLineEnd,
        );
    }

    private function foilUrl(Card $card, Extension $extension): ?string
    {
        if (null !== $card->getImageFoilName()) {
            return $this->uploaderHelper->asset($card, 'imageFoilFile');
        }

        // no upload: fall back to a bundled library texture if the visual
        // config picked one (card override beats the extension default)
        $texture = $card->getVisualConfigOverride()->foilTexture
            ?? $extension->getVisualConfig()->foilTexture;

        return $texture?->url();
    }

    private function maskUrl(Card $card): ?string
    {
        if (null !== $card->getImageMaskName()) {
            return $this->uploaderHelper->asset($card, 'imageMaskFile');
        }

        return null;
    }
}
