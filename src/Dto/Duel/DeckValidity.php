<?php

declare(strict_types=1);

namespace App\Dto\Duel;

/**
 * A deck checked against the owner's collection right now.
 */
final readonly class DeckValidity
{
    /**
     * @param list<string> $missingCards ids of the deck cards (terrain included) the owner no longer holds
     * @param list<string> $issues       player-facing French reasons, empty when valid
     */
    public function __construct(
        public array $missingCards,
        public array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->issues;
    }
}
