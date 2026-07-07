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
}
