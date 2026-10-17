<?php

declare(strict_types=1);

namespace App\Dto\Duel;

/**
 * One card of the duel game data, keyed by its ytcg uuid.
 */
final readonly class GameDataEntry
{
    /**
     * @param list<string>|null $tags non-universe tags, null = the file says nothing (left untouched)
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $terrain,
        public ?array $tags,
        public string $source,
    ) {
    }
}
