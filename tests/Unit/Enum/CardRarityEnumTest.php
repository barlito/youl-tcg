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

    public function testRankFollowsTheAscendingOrder(): void
    {
        // rank() and ascending() are two views of the same scale: lock them together
        foreach (CardRarityEnum::ascending() as $index => $rarity) {
            $this->assertSame($index, $rarity->rank());
        }
    }

    public function testLabelExposesTheFrenchPlayerFacingNames(): void
    {
        $this->assertSame('Commune', CardRarityEnum::COMMON->label());
        $this->assertSame('Peu commune', CardRarityEnum::UNCOMMON->label());
        $this->assertSame('Rare', CardRarityEnum::RARE->label());
        $this->assertSame('Légendaire', CardRarityEnum::LEGENDARY->label());
    }

    public function testRecyclePointsFollowTheAgreedScale(): void
    {
        $this->assertSame(1, CardRarityEnum::COMMON->recyclePoints());
        $this->assertSame(2, CardRarityEnum::UNCOMMON->recyclePoints());
        $this->assertSame(3, CardRarityEnum::RARE->recyclePoints());
        $this->assertSame(5, CardRarityEnum::LEGENDARY->recyclePoints());
    }

    public function testAHoloCopyIsWorthItsRarityPlusOne(): void
    {
        foreach (CardRarityEnum::cases() as $rarity) {
            $this->assertSame($rarity->recyclePoints() + 1, $rarity->holoRecyclePoints());
        }
    }

    public function testCompareRarestFirstSortsFromLegendaryToCommon(): void
    {
        $rarities = [
            CardRarityEnum::UNCOMMON,
            CardRarityEnum::LEGENDARY,
            CardRarityEnum::COMMON,
            CardRarityEnum::RARE,
        ];

        usort($rarities, CardRarityEnum::compareRarestFirst(...));

        $this->assertSame(array_reverse(CardRarityEnum::ascending()), $rarities);
        $this->assertSame(0, CardRarityEnum::compareRarestFirst(CardRarityEnum::RARE, CardRarityEnum::RARE));
    }
}
