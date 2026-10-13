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
     * @param list<string> $holoMissing  chosen holo cards the owner no longer holds in a holo copy: they play non-holo, the deck stays valid
     */
    public function __construct(
        public array $missingCards,
        public array $issues,
        public array $holoMissing = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->issues;
    }
}
