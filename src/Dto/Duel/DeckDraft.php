<?php

declare(strict_types=1);

namespace App\Dto\Duel;

use App\Entity\Card;

/**
 * A deck payload that passed every rule: resolved cards, ready to persist.
 */
final readonly class DeckDraft
{
    /**
     * @param list<Card> $cards
     */
    public function __construct(
        public string $name,
        public array $cards,
        public ?Card $terrain,
    ) {
    }
}
