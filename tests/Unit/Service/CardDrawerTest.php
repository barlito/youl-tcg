<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Booster\EmptyRarityRatesException;
use App\Exception\Booster\NoCardAvailableException;
use App\Repository\CardRepository;
use App\Service\Booster\CardDrawer;
use App\Service\Random\RandomService;
use PHPUnit\Framework\TestCase;

final class CardDrawerTest extends TestCase
{
    public function testDrawsOneCardPerSlot(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Common card', CardRarityEnum::COMMON),
            $this->card('Rare card', CardRarityEnum::RARE),
        ]);

        $drawnCards = $drawer->draw($this->booster([
            ['common' => 100],
            ['rare' => 100],
            ['common' => 50, 'rare' => 50],
        ]));

        $this->assertCount(3, $drawnCards);
        $this->assertSame('Common card', $drawnCards[0]->card->getName());
        $this->assertSame(CardRarityEnum::COMMON, $drawnCards[0]->rarity);
        $this->assertSame('Rare card', $drawnCards[1]->card->getName());
        $this->assertSame(CardRarityEnum::RARE, $drawnCards[1]->rarity);
    }

    public function testWeightedDistributionOverManyDraws(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Common card', CardRarityEnum::COMMON),
            $this->card('Rare card', CardRarityEnum::RARE),
            $this->card('Legendary card', CardRarityEnum::LEGENDARY),
        ]);
        $booster = $this->booster([['common' => 60, 'rare' => 30, 'legendary' => 10]]);

        $draws = 10_000;
        $counts = [CardRarityEnum::COMMON->value => 0, CardRarityEnum::RARE->value => 0, CardRarityEnum::LEGENDARY->value => 0];

        for ($i = 0; $i < $draws; ++$i) {
            ++$counts[$drawer->draw($booster)[0]->rarity->value];
        }

        $this->assertEqualsWithDelta(0.60, $counts['common'] / $draws, 0.03);
        $this->assertEqualsWithDelta(0.30, $counts['rare'] / $draws, 0.03);
        $this->assertEqualsWithDelta(0.10, $counts['legendary'] / $draws, 0.03);
    }

    public function testFallsBackTowardCommonWhenRolledRarityHasNoCard(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Uncommon card', CardRarityEnum::UNCOMMON),
        ]);

        $drawnCards = $drawer->draw($this->booster([['legendary' => 100]]));

        $this->assertSame(CardRarityEnum::UNCOMMON, $drawnCards[0]->rarity);
        $this->assertSame('Uncommon card', $drawnCards[0]->card->getName());
    }

    public function testFallsBackTowardLegendaryWhenNothingBelowRolledRarity(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Legendary card', CardRarityEnum::LEGENDARY),
        ]);

        $drawnCards = $drawer->draw($this->booster([['common' => 100]]));

        $this->assertSame(CardRarityEnum::LEGENDARY, $drawnCards[0]->rarity);
    }

    public function testDrawnRarityMatchesTheCardRarity(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Common card', CardRarityEnum::COMMON),
            $this->card('Legendary card', CardRarityEnum::LEGENDARY),
        ]);
        $booster = $this->booster([['common' => 50, 'legendary' => 50]]);

        for ($i = 0; $i < 100; ++$i) {
            $drawnCard = $drawer->draw($booster)[0];

            $this->assertSame($drawnCard->card->getRarity(), $drawnCard->rarity);
        }
    }

    public function testZeroHoloChanceNeverDrawsHolo(): void
    {
        $drawer = $this->createDrawer([$this->card('Common card', CardRarityEnum::COMMON)]);
        $booster = $this->booster([['common' => 100]], holoChance: 0);

        for ($i = 0; $i < 200; ++$i) {
            $this->assertFalse($drawer->draw($booster)[0]->holo);
        }
    }

    public function testFullHoloChanceAlwaysDrawsHolo(): void
    {
        $drawer = $this->createDrawer([$this->card('Common card', CardRarityEnum::COMMON)]);
        $booster = $this->booster([['common' => 100]], holoChance: 100);

        for ($i = 0; $i < 200; ++$i) {
            $this->assertTrue($drawer->draw($booster)[0]->holo);
        }
    }

    public function testAlwaysHoloCardIsHoloEvenWithZeroChance(): void
    {
        $card = $this->card('Legendary card', CardRarityEnum::LEGENDARY)->setAlwaysHolo(true);
        $drawer = $this->createDrawer([$card]);
        $booster = $this->booster([['legendary' => 100]], holoChance: 0);

        for ($i = 0; $i < 200; ++$i) {
            $this->assertTrue($drawer->draw($booster)[0]->holo);
        }
    }

    public function testHoloChanceIsResolvedPerSlot(): void
    {
        $drawer = $this->createDrawer([$this->card('Common card', CardRarityEnum::COMMON)]);
        $booster = new Booster()
            ->setExtension($this->extension())
            ->setRarityRates([
                ['rarities' => ['common' => 100], 'holoChance' => 0],
                ['rarities' => ['common' => 100], 'holoChance' => 100],
            ])
        ;

        for ($i = 0; $i < 200; ++$i) {
            $drawnCards = $drawer->draw($booster);
            $this->assertFalse($drawnCards[0]->holo);
            $this->assertTrue($drawnCards[1]->holo);
        }
    }

    public function testThrowsWhenRarityRatesAreEmpty(): void
    {
        // The guard must fire before any pool load or roll: an empty slot list
        // would otherwise "draw" zero cards while the caller already debited
        // the booster in the same transaction.
        $cardRepository = $this->createMock(CardRepository::class);
        $cardRepository->expects($this->never())->method('findDrawablePool');
        $drawer = new CardDrawer($cardRepository, new RandomService());

        $booster = new Booster()
            ->setExtension($this->extension())
            ->setRarityRates([])
        ;

        $this->expectException(EmptyRarityRatesException::class);

        $drawer->draw($booster);
    }

    public function testThrowsWhenExtensionHasNoPublishedCard(): void
    {
        $drawer = $this->createDrawer([]);

        $this->expectException(NoCardAvailableException::class);

        $drawer->draw($this->booster([['common' => 100]]));
    }

    public function testDrawReplacementNeverReturnsAUniqueCard(): void
    {
        $unique = $this->card('Unique rare', CardRarityEnum::RARE)->setUnique(true);
        $drawer = $this->createDrawer([
            $unique,
            $this->card('Plain rare', CardRarityEnum::RARE),
        ]);
        $booster = $this->booster([['rare' => 100]]);

        for ($i = 0; $i < 100; ++$i) {
            $replacement = $drawer->drawReplacement($booster, CardRarityEnum::RARE);

            $this->assertFalse($replacement->card->isUnique());
            $this->assertSame('Plain rare', $replacement->card->getName());
        }
    }

    public function testDrawReplacementThrowsWhenOnlyUniqueCardsExist(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Unique rare', CardRarityEnum::RARE)->setUnique(true),
        ]);

        $this->expectException(NoCardAvailableException::class);

        $drawer->drawReplacement($this->booster([['rare' => 100]]), CardRarityEnum::RARE);
    }

    public function testDrawReplacementKeepsTheHoloRolledBySlot(): void
    {
        $drawer = $this->createDrawer([
            $this->card('Plain rare', CardRarityEnum::RARE),
        ]);
        $booster = $this->booster([['rare' => 100]]);

        // Losing the unique must not also cost the holo the slot rolled.
        $this->assertTrue($drawer->drawReplacement($booster, CardRarityEnum::RARE, holo: true)->holo);
        $this->assertFalse($drawer->drawReplacement($booster, CardRarityEnum::RARE, holo: false)->holo);
    }

    public function testDrawReplacementDoesNotDesyncTheSeededMainStream(): void
    {
        $cards = [
            $this->card('Common A', CardRarityEnum::COMMON),
            $this->card('Common B', CardRarityEnum::COMMON),
            $this->card('Plain rare', CardRarityEnum::RARE),
        ];
        $booster = $this->booster([['common' => 70, 'rare' => 30], ['common' => 70, 'rare' => 30]]);

        $names = static fn (array $drawnCards): array => array_map(
            static fn ($drawnCard): string => $drawnCard->card->getName() . ($drawnCard->holo ? '*' : ''),
            $drawnCards,
        );

        // Reference: seeded draw with no replacement roll in between.
        $reference = new RandomService();
        $reference->seed(20260706);
        $referenceDraw = $names(new CardDrawer($this->repositoryWith($cards), $reference)->draw($booster));

        // Same seed, but a replacement roll happens first — the audit guarantee
        // is that the stored seed still replays the main draw identically.
        $random = new RandomService();
        $random->seed(20260706);
        $drawer = new CardDrawer($this->repositoryWith($cards), $random);
        $drawer->drawReplacement($booster, CardRarityEnum::RARE);
        $this->assertSame($referenceDraw, $names($drawer->draw($booster)));
    }

    public function testSameSeedReproducesTheSameDraw(): void
    {
        $cards = [
            $this->card('Common A', CardRarityEnum::COMMON),
            $this->card('Common B', CardRarityEnum::COMMON),
            $this->card('Rare card', CardRarityEnum::RARE),
        ];
        $booster = $this->booster([['common' => 70, 'rare' => 30], ['common' => 70, 'rare' => 30]]);

        $names = static fn (array $drawnCards): array => array_map(
            static fn ($drawnCard): string => $drawnCard->card->getName() . ($drawnCard->holo ? '*' : ''),
            $drawnCards,
        );

        $firstRandom = new RandomService();
        $firstRandom->seed(20260610);
        $firstDraw = $names(new CardDrawer($this->repositoryWith($cards), $firstRandom)->draw($booster));

        $secondRandom = new RandomService();
        $secondRandom->seed(20260610);
        $secondDraw = $names(new CardDrawer($this->repositoryWith($cards), $secondRandom)->draw($booster));

        $this->assertSame($firstDraw, $secondDraw);
    }

    /**
     * @param list<Card> $publishedCards
     */
    private function createDrawer(array $publishedCards): CardDrawer
    {
        $randomService = new RandomService();
        $randomService->seed(424242);

        return new CardDrawer($this->repositoryWith($publishedCards), $randomService);
    }

    /**
     * @param list<Card> $publishedCards
     */
    private function repositoryWith(array $publishedCards): CardRepository
    {
        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findDrawablePool')->willReturn($publishedCards);

        return $cardRepository;
    }

    private function card(string $name, CardRarityEnum $rarity): Card
    {
        return new Card()
            ->setName($name)
            ->setRarity($rarity)
            ->setExtension($this->extension())
        ;
    }

    /**
     * Wraps plain rarity weight maps into the per-slot shape
     * ({rarities, holoChance}), applying the same holo chance to every slot.
     *
     * @param list<array<string, int>> $raritySlots
     */
    private function booster(array $raritySlots, int $holoChance = 10): Booster
    {
        $rarityRates = array_map(
            static fn (array $rarities): array => ['rarities' => $rarities, 'holoChance' => $holoChance],
            $raritySlots,
        );

        return new Booster()
            ->setExtension($this->extension())
            ->setRarityRates($rarityRates)
        ;
    }

    private function extension(): Extension
    {
        return $this->extensionInstance ??= new Extension()->setName('Test extension');
    }

    private ?Extension $extensionInstance = null;
}
