<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use PHPUnit\Framework\TestCase;

final class BoosterOpeningTest extends TestCase
{
    public function testDrawnCardsAreSortedRarestFirstThenByName(): void
    {
        $opening = $this->createOpening();
        $this->drawCard($opening, 'Zangetsu', CardRarityEnum::COMMON);
        $this->drawCard($opening, 'Ichigo', CardRarityEnum::LEGENDARY);
        $this->drawCard($opening, 'Aizen', CardRarityEnum::COMMON);
        $this->drawCard($opening, 'Rukia', CardRarityEnum::RARE);

        $names = array_map(
            static fn (BoosterOpeningCard $drawnCard): string => $drawnCard->getCard()->getName(),
            $opening->getDrawnCards(),
        );

        $this->assertSame(['Ichigo', 'Rukia', 'Aizen', 'Zangetsu'], $names);
    }

    public function testCountersAggregateTheDuplicates(): void
    {
        $opening = $this->createOpening();
        $this->drawCard($opening, 'Zangetsu', CardRarityEnum::COMMON, quantity: 3, holoQuantity: 1);
        $this->drawCard($opening, 'Rukia', CardRarityEnum::RARE, quantity: 2, holoQuantity: 2);

        $this->assertSame(5, $opening->getDrawnCardCount());
        $this->assertSame(3, $opening->getHoloCount());
        $this->assertSame(CardRarityEnum::RARE, $opening->getBestRarity());
    }

    public function testAnOpeningWithoutCardHasNoBestRarity(): void
    {
        $opening = $this->createOpening();

        $this->assertSame(0, $opening->getDrawnCardCount());
        $this->assertSame(0, $opening->getHoloCount());
        $this->assertNull($opening->getBestRarity());
    }

    private function createOpening(): BoosterOpening
    {
        return new BoosterOpening(new DiscordUser(), new Booster(), 42, new \DateTimeImmutable());
    }

    private function drawCard(
        BoosterOpening $opening,
        string $name,
        CardRarityEnum $rarity,
        int $quantity = 1,
        int $holoQuantity = 0,
    ): void {
        $card = new Card()->setName($name)->setRarity($rarity);

        $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $card, $quantity, $holoQuantity));
    }
}
