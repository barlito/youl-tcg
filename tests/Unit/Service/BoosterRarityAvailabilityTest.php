<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterRarityAvailability;
use PHPUnit\Framework\TestCase;

final class BoosterRarityAvailabilityTest extends TestCase
{
    private const string EXTENSION_ID = '01a0f395-ab36-7e83-9765-f32130d17225';

    public function testWeightedRaritiesWithoutAnyDrawableCardAreReported(): void
    {
        $availability = new BoosterRarityAvailability($this->cardRepository(CardRarityEnum::COMMON, CardRarityEnum::RARE));

        $booster = new Booster()
            ->setExtension($this->extension())
            ->setRarityRates([
                ['rarities' => ['common' => 70, 'legendary' => 30], 'holoChance' => 0],
                ['rarities' => ['rare' => 50, 'uncommon' => 50], 'holoChance' => 10],
            ])
        ;

        $this->assertSame(
            [CardRarityEnum::UNCOMMON, CardRarityEnum::LEGENDARY],
            $availability->findUnavailableRarities($booster),
        );
    }

    public function testNothingIsReportedWhenEveryWeightedRarityIsDrawable(): void
    {
        $availability = new BoosterRarityAvailability($this->cardRepository(CardRarityEnum::COMMON, CardRarityEnum::LEGENDARY));

        $booster = new Booster()
            ->setExtension($this->extension())
            ->setRarityRates([['rarities' => ['common' => 90, 'legendary' => 10], 'holoChance' => 0]])
        ;

        $this->assertSame([], $availability->findUnavailableRarities($booster));
    }

    public function testABoosterWithoutExtensionIsNotChecked(): void
    {
        $cardRepository = $this->createMock(CardRepository::class);
        $cardRepository->expects($this->never())->method('findDrawableRaritiesByExtension');

        $booster = new Booster()->setRarityRates([['rarities' => ['legendary' => 1], 'holoChance' => 0]]);

        $this->assertSame([], new BoosterRarityAvailability($cardRepository)->findUnavailableRarities($booster));
    }

    public function testTheDrawableRaritiesAreQueriedOnce(): void
    {
        $extension = $this->extension();
        $cardRepository = $this->createMock(CardRepository::class);
        $cardRepository->expects($this->once())
            ->method('findDrawableRaritiesByExtension')
            ->willReturn([self::EXTENSION_ID => ['common']])
        ;

        $availability = new BoosterRarityAvailability($cardRepository);
        $rates = [['rarities' => ['common' => 1, 'rare' => 1], 'holoChance' => 0]];

        $first = new Booster()->setExtension($extension)->setRarityRates($rates);
        $second = new Booster()->setExtension($extension)->setRarityRates($rates);

        $this->assertSame([CardRarityEnum::RARE], $availability->findUnavailableRarities($first));
        $this->assertSame([CardRarityEnum::RARE], $availability->findUnavailableRarities($second));
    }

    private function cardRepository(CardRarityEnum ...$drawableRarities): CardRepository
    {
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findDrawableRaritiesByExtension')->willReturn([
            self::EXTENSION_ID => array_map(static fn (CardRarityEnum $rarity): string => $rarity->value, $drawableRarities),
        ]);

        return $cardRepository;
    }

    private function extension(): Extension
    {
        return new Extension()->setName('Bleach')->setId(self::EXTENSION_ID);
    }
}
