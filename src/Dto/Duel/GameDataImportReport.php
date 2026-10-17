<?php

declare(strict_types=1);

namespace App\Dto\Duel;

final readonly class GameDataImportReport
{
    /**
     * @param list<array{id: string, name: string, tagsBefore: list<string>, tagsAfter: list<string>, terrainBefore: bool, terrainAfter: bool}> $changes
     * @param list<GameDataEntry>                                                                                                               $unknown
     */
    public function __construct(
        public array $changes,
        public int $unchanged,
        public array $unknown,
    ) {
    }
}
