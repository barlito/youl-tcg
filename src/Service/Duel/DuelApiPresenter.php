<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Dto\Duel\DeckValidity;
use App\Entity\Deck;
use App\Entity\UserCard;

final class DuelApiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function collectionCard(UserCard $userCard): array
    {
        $card = $userCard->getCard();

        return [
            'id' => (string) $card->getId(),
            'name' => $card->getName(),
            'extension' => $card->getExtension()?->getSlug(),
            'rarity' => $card->getRarity()->value,
            'unique' => $card->isUnique(),
            'terrain' => $card->isTerrain(),
            'tags' => $card->getTags(),
            'quantity' => $userCard->getQuantity(),
            'holoQuantity' => $userCard->getHoloQuantity(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deck(Deck $deck, DeckValidity $validity): array
    {
        return [
            'id' => (string) $deck->getId(),
            'name' => $deck->getName(),
            'cards' => $deck->getCardIds(),
            'terrain' => $deck->getTerrain()?->getId(),
            'valid' => $validity->isValid(),
            'missingCards' => $validity->missingCards,
            'issues' => $validity->issues,
            'createdAt' => $deck->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $deck->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }
}
