<?php

declare(strict_types=1);

namespace App\Service\Duel;

use App\Dto\Duel\DeckDraft;
use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\UserCard;
use App\Exception\Duel\DeckRefusedException;
use Symfony\Component\Uid\Uuid;

/**
 * Ownership rules only (costs and curve belong to the game server); unknown, unpublished and not owned share one message.
 */
final class DeckInputValidator
{
    /**
     * @param array<mixed>            $payload
     * @param array<string, UserCard> $collection the player's duel collection, card id => row
     *
     * @throws DeckRefusedException
     */
    public function validate(array $payload, array $collection): DeckDraft
    {
        $violations = [];

        $name = $this->validateName($payload['name'] ?? null, $violations);
        $cards = $this->validateCards($payload['cards'] ?? null, $collection, $violations);
        $terrain = $this->validateTerrain($payload['terrain'] ?? null, $collection, $violations);
        $holoCards = $this->validateHoloCards($payload['holoCards'] ?? [], $cards, $collection, $violations);
        $this->validateForcedHolo($payload['cards'] ?? null, $holoCards, $collection, $violations);

        if ([] !== $violations) {
            throw DeckRefusedException::invalid($violations);
        }

        return new DeckDraft($name, $cards, $terrain, $holoCards);
    }

    /**
     * @param array<string, list<string>> $violations
     */
    private function validateName(mixed $value, array &$violations): string
    {
        if (!\is_string($value) || '' === trim($value)) {
            $violations['name'][] = 'Donne un nom à ton deck.';

            return '';
        }

        $name = trim($value);
        if (mb_strlen($name) > Deck::NAME_MAX_LENGTH) {
            $violations['name'][] = \sprintf('%d caractères maximum.', Deck::NAME_MAX_LENGTH);
        }

        return $name;
    }

    /**
     * @param array<string, UserCard>     $collection
     * @param array<string, list<string>> $violations
     *
     * @return list<Card>
     */
    private function validateCards(mixed $value, array $collection, array &$violations): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            $violations['cards'][] = \sprintf('Liste de %d identifiants de cartes attendue.', Deck::SIZE);

            return [];
        }

        if (Deck::SIZE !== \count($value)) {
            $violations['cards'][] = \sprintf('Un deck contient exactement %d cartes (reçu : %d).', Deck::SIZE, \count($value));
        }

        $cards = [];
        foreach ($value as $index => $id) {
            $path = \sprintf('cards[%d]', $index);
            $id = \is_string($id) && Uuid::isValid($id) ? strtolower($id) : null;

            if (null === $id) {
                $violations[$path][] = 'Identifiant de carte invalide.';
                continue;
            }
            if (isset($cards[$id])) {
                $violations[$path][] = 'Carte en double : chaque carte ne figure qu\'une fois par deck.';
                continue;
            }

            $card = ($collection[$id] ?? null)?->getCard();
            if (!$card instanceof Card) {
                $violations[$path][] = 'Carte introuvable dans ta collection.';
                continue;
            }
            if ($card->isTerrain()) {
                $violations[$path][] = \sprintf('« %s » est un terrain : place-le dans le champ terrain.', $card->getName());
                continue;
            }

            $cards[$id] = $card;
        }

        return array_values($cards);
    }

    /**
     * @param array<string, UserCard>     $collection
     * @param array<string, list<string>> $violations
     */
    private function validateTerrain(mixed $value, array $collection, array &$violations): ?Card
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value) || !Uuid::isValid($value)) {
            $violations['terrain'][] = 'Identifiant de terrain invalide.';

            return null;
        }

        $terrain = ($collection[strtolower($value)] ?? null)?->getCard();
        if (!$terrain instanceof Card) {
            $violations['terrain'][] = 'Terrain introuvable dans ta collection.';

            return null;
        }
        if (!$terrain->isTerrain()) {
            $violations['terrain'][] = \sprintf('« %s » n\'est pas un terrain.', $terrain->getName());

            return null;
        }

        return $terrain;
    }

    /**
     * The player picks which cards of the deck are played in their holo copy: only cards of the deck, owned in a holo copy.
     *
     * @param list<Card>                  $cards
     * @param array<string, UserCard>     $collection
     * @param array<string, list<string>> $violations
     *
     * @return list<Card>
     */
    private function validateHoloCards(mixed $value, array $cards, array $collection, array &$violations): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            $violations['holoCards'][] = 'Liste d\'identifiants de cartes du deck attendue.';

            return [];
        }

        $inDeck = [];
        foreach ($cards as $card) {
            $inDeck[(string) $card->getId()] = $card;
        }

        $holo = [];
        foreach ($value as $index => $id) {
            $path = \sprintf('holoCards[%d]', $index);
            $id = \is_string($id) && Uuid::isValid($id) ? strtolower($id) : null;

            if (null === $id) {
                $violations[$path][] = 'Identifiant de carte invalide.';
            } elseif (!isset($inDeck[$id])) {
                $violations[$path][] = 'Une carte holo doit faire partie du deck.';
            } elseif (($collection[$id] ?? null)?->getHoloQuantity() <= 0) {
                $violations[$path][] = \sprintf('Tu ne possèdes pas d\'exemplaire holo de « %s ».', $inDeck[$id]->getName());
            } else {
                $holo[$id] = $inDeck[$id];
            }
        }

        return array_values($holo);
    }

    /**
     * Owning only holo copies of a card means playing it holo: refusing to flag it is refused, not silently fixed.
     *
     * @param list<Card>                  $holoCards
     * @param array<string, UserCard>     $collection
     * @param array<string, list<string>> $violations
     */
    private function validateForcedHolo(mixed $sent, array $holoCards, array $collection, array &$violations): void
    {
        if (!\is_array($sent) || !array_is_list($sent)) {
            return;
        }

        $flagged = array_map(static fn (Card $card): string => (string) $card->getId(), $holoCards);
        foreach ($sent as $index => $id) {
            $key = \is_string($id) ? strtolower($id) : '';
            $row = $collection[$key] ?? null;
            if ($row instanceof UserCard && $row->getHoloQuantity() > 0 && $row->getNormalQuantity() <= 0 && !\in_array($key, $flagged, true)) {
                $violations[\sprintf('cards[%d]', $index)][] = \sprintf('Tu ne possèdes que des exemplaires holo de « %s » : ajoute-la à holoCards.', $row->getCard()->getName());
            }
        }
    }
}
