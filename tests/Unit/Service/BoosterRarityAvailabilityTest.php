<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterRarityAvailability;
use PHPUnit\Framework\TestCase;

final class BoosterRarityAvailabilityTest extends TestCase
{
    public function testWeightedRaritiesWithoutAnyDrawableCardAreReported(): void
    {
        $availability = new BoosterRarityAvailability($this->cardRepository(CardRarityEnum::COMMON, CardRarityEnum::RARE));

        $booster = new Booster()
            ->setExtension(new Extension()->setName('Bleach'))
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
            ->setExtension(new Extension()->setName('Bleach'))
            ->setRarityRates([['rarities' => ['common' => 90, 'legendary' => 10], 'holoChance' => 0]])
        ;

        $this->assertSame([], $availability->findUnavailableRarities($booster));
    }

    public function testATierHoldingOnlyAUniqueIsReportedAsUnavailable(): void
    {
        // uniques never come out of the rarity roll: weighting legendary while
        // the only legendary is a 1/1 is the same misconfiguration as an empty tier
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findDrawablePool')->willReturn([
            new Card()->setRarity(CardRarityEnum::COMMON),
            new Card()->setRarity(CardRarityEnum::LEGENDARY)->setUnique(true),
        ]);

        $booster = new Booster()
            ->setExtension(new Extension()->setName('Bleach'))
            ->setRarityRates([['rarities' => ['common' => 90, 'legendary' => 10], 'holoChance' => 0]])
        ;

        $this->assertSame(
            [CardRarityEnum::LEGENDARY],
            new BoosterRarityAvailability($cardRepository)->findUnavailableRarities($booster),
        );
    }

    public function testABoosterWithoutExtensionIsNotChecked(): void
    {
        $cardRepository = $this->createMock(CardRepository::class);
        $cardRepository->expects($this->never())->method('findDrawablePool');

        $booster = new Booster()->setRarityRates([['rarities' => ['legendary' => 1], 'holoChance' => 0]]);

        $this->assertSame([], new BoosterRarityAvailability($cardRepository)->findUnavailableRarities($booster));
    }

    public function testTheDrawablePoolIsQueriedOncePerExtension(): void
    {
        $extension = new Extension()->setName('Bleach');
        $cardRepository = $this->createMock(CardRepository::class);
        $cardRepository->expects($this->once())
            ->method('findDrawablePool')
            ->willReturn([new Card()->setRarity(CardRarityEnum::COMMON)])
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
        $cards = array_map(
            static fn (CardRarityEnum $rarity): Card => new Card()->setRarity($rarity),
            $drawableRarities,
        );

        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findDrawablePool')->willReturn($cards);

        return $cardRepository;
    }
}
