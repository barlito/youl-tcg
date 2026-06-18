<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Entity\CardRarityEnum;
use PHPUnit\Framework\TestCase;

final class CardRarityEnumTest extends TestCase
{
    public function testScaleHasFourTiersWithoutEpic(): void
    {
        $values = array_map(static fn (CardRarityEnum $rarity): string => $rarity->value, CardRarityEnum::cases());

        $this->assertSame(['common', 'uncommon', 'rare', 'legendary'], $values);
        $this->assertNull(CardRarityEnum::tryFrom('epic'));
    }

    public function testAscendingIsOrderedLeastToMostRare(): void
    {
        $this->assertSame(
            [
                CardRarityEnum::COMMON,
                CardRarityEnum::UNCOMMON,
                CardRarityEnum::RARE,
                CardRarityEnum::LEGENDARY,
            ],
            CardRarityEnum::ascending(),
        );
    }
}
