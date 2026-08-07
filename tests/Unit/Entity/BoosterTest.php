<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Booster;
use App\Entity\Extension;
use PHPUnit\Framework\TestCase;

final class BoosterTest extends TestCase
{
    public function testDisplayNameFallsBackToTheExtension(): void
    {
        $booster = new Booster()->setExtension(new Extension()->setName('Bleach'));

        $this->assertSame('Bleach', $booster->getDisplayName());

        $booster->setName('Pack Full Rare');
        $this->assertSame('Pack Full Rare', $booster->getDisplayName());

        // blank names are normalised to null (fallback restored)
        $booster->setName('   ');
        $this->assertSame('Bleach', $booster->getDisplayName());
    }

    public function testClaimableDefaultsToTrue(): void
    {
        $this->assertTrue(new Booster()->isClaimable());
        $this->assertFalse(new Booster()->setClaimable(false)->isClaimable());
    }

    public function testDropRatesNormaliseTheSlotWeights(): void
    {
        $booster = new Booster()->setRarityRates([
            ['rarities' => ['common' => 100], 'holoChance' => 5],
            ['rarities' => ['common' => 60, 'rare' => 30, 'legendary' => 10], 'holoChance' => 30],
            ['rarities' => ['common' => 1, 'rare' => 2], 'holoChance' => 0],
        ]);

        $this->assertSame([
            ['rates' => ['common' => 100.0], 'holoChance' => 5],
            ['rates' => ['common' => 60.0, 'rare' => 30.0, 'legendary' => 10.0], 'holoChance' => 30],
            ['rates' => ['common' => 33.3, 'rare' => 66.7], 'holoChance' => 0],
        ], $booster->getDropRates());
    }

    public function testSlotsAreReindexedSoTheJsonStaysAList(): void
    {
        // the admin collection form submits the surviving slots with their
        // original keys once one is deleted in the middle
        $booster = new Booster()->setRarityRates([
            0 => ['rarities' => ['common' => 100], 'holoChance' => 0],
            2 => ['rarities' => ['rare' => 100], 'holoChance' => 50],
        ]);

        $this->assertSame([0, 1], array_keys($booster->getRarityRates()));
        $this->assertSame(2, $booster->getCardCount());
        $this->assertSame('[{"rarities":{"common":100},"holoChance":0},{"rarities":{"rare":100},"holoChance":50}]', json_encode($booster->getRarityRates()));
    }

    public function testPercentagesAreProjectedFromRelativeWeights(): void
    {
        $this->assertSame(['common' => 60.0, 'rare' => 40.0], Booster::toPercentages(['common' => 6, 'rare' => 4]));
        $this->assertSame(['common' => 100.0], Booster::toPercentages(['common' => 1]));
        $this->assertSame([], Booster::toPercentages([]));
    }

    public function testDropRatesAreEmptyWithoutAnySlot(): void
    {
        $this->assertSame([], new Booster()->getDropRates());
        $this->assertSame(0, new Booster()->getCardCount());
    }
}
