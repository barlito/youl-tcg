<?php

declare(strict_types=1);

namespace App\Exception\Duel;

/**
 * A deck write refused for the player, rendered as JSON by the duel API.
 */
final class DeckRefusedException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $violations
     */
    private function __construct(
        public readonly int $status,
        string $message,
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, list<string>> $violations
     */
    public static function invalid(array $violations): self
    {
        return new self(422, 'Deck invalide.', $violations);
    }

    public static function limitReached(int $max): self
    {
        return new self(409, \sprintf('Tu as déjà %d decks : supprimes-en un avant d\'en créer un nouveau.', $max));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['error' => $this->getMessage(), ...([] === $this->violations ? [] : ['violations' => $this->violations])];
    }
}
