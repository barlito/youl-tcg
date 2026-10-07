<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Dto\Duel\DeckValidity;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\UserCard;

/**
 * Validity of a stored deck against the owner's collection NOW: sold, traded or recycled cards make it incomplete.
 */
final class DeckValidator
{
    /**
     * @param array<string, UserCard> $collection the owner's duel collection, card id => row
     */
    public function check(Deck $deck, array $collection): DeckValidity
    {
        $missing = [];
        $issues = [];

        $cards = $deck->getCards();
        if (\count($cards) < Deck::SIZE) {
            $issues[] = \sprintf('Il manque %d carte%s : un deck en contient %d.', Deck::SIZE - \count($cards), Deck::SIZE - \count($cards) > 1 ? 's' : '', Deck::SIZE);
        }

        foreach ($cards as $card) {
            $id = (string) $card->getId();
            if (!isset($collection[$id])) {
                $missing[] = $id;
            } elseif ($card->isTerrain()) {
                $issues[] = \sprintf('« %s » est désormais un terrain : il ne se joue plus comme carte.', $card->getName());
            }
        }
        sort($missing);

        if ([] !== $missing) {
            $count = \count($missing);
            $issues[] = \sprintf('%d carte%s du deck %s plus dans ta collection.', $count, $count > 1 ? 's' : '', $count > 1 ? 'ne sont' : 'n\'est');
        }

        $terrain = $deck->getTerrain();
        if ($terrain instanceof Card) {
            if (!isset($collection[(string) $terrain->getId()])) {
                $missing[] = (string) $terrain->getId();
                $issues[] = 'Ton terrain n\'est plus dans ta collection.';
            } elseif (!$terrain->isTerrain()) {
                $issues[] = \sprintf('« %s » n\'est plus un terrain.', $terrain->getName());
            }
        }

        return new DeckValidity($missing, $issues);
    }
}
