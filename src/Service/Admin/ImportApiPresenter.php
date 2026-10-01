<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Card;
use App\Entity\Extension;

final readonly class ImportApiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function extension(Extension $extension, int $cardCount): array
    {
        return [
            'id' => (string) $extension->getId(),
            'name' => $extension->getName(),
            'slug' => $extension->getSlug(),
            'status' => $extension->getStatus()->name,
            'cardCount' => $cardCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Card $card): array
    {
        return [
            'id' => (string) $card->getId(),
            'name' => $card->getName(),
            'status' => $card->getStatus()->name,
            'rarity' => $card->getRarity()->value,
            'imageName' => $card->getImageName(),
            'imageMaskName' => $card->getImageMaskName(),
            'imageFoilName' => $card->getImageFoilName(),
        ];
    }
}
