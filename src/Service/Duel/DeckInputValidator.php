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

        if ([] !== $violations) {
            throw DeckRefusedException::invalid($violations);
        }

        return new DeckDraft($name, $cards, $terrain);
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
}
