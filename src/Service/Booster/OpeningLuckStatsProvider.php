<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\BestPull;
use App\Dto\BoosterLuckStats;
use App\Dto\OpeningLuckStats;
use App\Entity\Booster;
use App\Entity\BoosterOpeningCard;
use App\Entity\DiscordUser;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\BoosterOpeningCardRepository;
use App\Repository\BoosterOpeningRepository;
use App\Repository\BoosterRepository;

/**
 * Builds the « Ma chance » sheet from SQL aggregates only — the history can
 * grow forever, nothing here ever hydrates it.
 */
final readonly class OpeningLuckStatsProvider
{
    public function __construct(
        private BoosterOpeningRepository $boosterOpeningRepository,
        private BoosterOpeningCardRepository $boosterOpeningCardRepository,
        private BoosterRepository $boosterRepository,
    ) {
    }

    public function getStats(DiscordUser $user): OpeningLuckStats
    {
        $openingCount = $this->boosterOpeningRepository->countByUser($user);

        if (0 === $openingCount) {
            return new OpeningLuckStats(0, 0, 0, 0.0, [], [], [], null);
        }

        ['cards' => $cardCount, 'holos' => $holoCount] = $this->boosterOpeningCardRepository->sumPulledForUser($user);
        $pulledPerRarity = $this->boosterOpeningCardRepository->countPerRarityForUser($user);

        $rarityCounts = [];
        $rarityShares = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            $count = $pulledPerRarity[$rarity->value] ?? 0;
            $rarityCounts[$rarity->value] = $count;
            $rarityShares[$rarity->value] = $this->share($count, $cardCount);
        }

        return new OpeningLuckStats(
            $openingCount,
            $cardCount,
            $holoCount,
            $this->share($holoCount, $cardCount),
            $rarityCounts,
            $rarityShares,
            $this->buildBoosterLucks($user),
            $this->buildBestPull($user),
        );
    }

    /**
     * @return list<BoosterLuckStats>
     */
    private function buildBoosterLucks(DiscordUser $user): array
    {
        $openingsPerBooster = $this->boosterOpeningRepository->countPerBoosterForUser($user);

        if ([] === $openingsPerBooster) {
            return [];
        }

        $pullsPerBooster = $this->boosterOpeningCardRepository->aggregatePerBoosterForUser($user);

        $boosters = [];

        foreach ($this->boosterRepository->findBy(['id' => array_keys($openingsPerBooster)]) as $booster) {
            $boosterId = (string) $booster->getId();
            $pulls = $pullsPerBooster[$boosterId] ?? ['cards' => 0, 'holos' => 0, 'rarities' => []];
            ['expectedShares' => $expectedShares, 'expectedHoloRate' => $expectedHoloRate] = $this->expectedRates($booster);

            $rarityRows = [];

            foreach (CardRarityEnum::ascending() as $rarity) {
                $observed = $this->share($pulls['rarities'][$rarity->value] ?? 0, $pulls['cards']);
                $expected = $expectedShares[$rarity->value] ?? 0.0;

                // only tiers involved on either side: no row of double zeros
                if ($observed > 0 || $expected > 0) {
                    $rarityRows[$rarity->value] = ['observed' => $observed, 'expected' => $expected];
                }
            }

            $boosters[] = new BoosterLuckStats(
                $booster,
                $openingsPerBooster[$boosterId] ?? 0,
                $pulls['cards'],
                $this->share($pulls['holos'], $pulls['cards']),
                $expectedHoloRate,
                $rarityRows,
            );
        }

        usort($boosters, static fn (BoosterLuckStats $left, BoosterLuckStats $right): int => [$right->openingCount, $left->booster->getDisplayName()] <=> [$left->openingCount, $right->booster->getDisplayName()]);

        return $boosters;
    }

    private function buildBestPull(DiscordUser $user): ?BestPull
    {
        $pull = $this->boosterOpeningCardRepository->findBestPullForUser($user);

        if (!$pull instanceof BoosterOpeningCard) {
            return null;
        }

        return new BestPull(
            $pull->getCard(),
            $pull->getCard()->getRarity(),
            $pull->getBoosterOpening()->getOpenedAt(),
            $pull->getHoloQuantity() > 0,
        );
    }

    /**
     * The pack's CURRENT advertised rates, projected per card: every slot
     * yields exactly one card, so the expected share of a rarity is the mean
     * of its per-slot percentages (same for the holo chance).
     *
     * @return array{expectedShares: array<string, float>, expectedHoloRate: float}
     */
    private function expectedRates(Booster $booster): array
    {
        $slots = $booster->getDropRates();
        $slotCount = \count($slots);

        if (0 === $slotCount) {
            return ['expectedShares' => [], 'expectedHoloRate' => 0.0];
        }

        $rateSums = [];
        $holoSum = 0;

        foreach ($slots as $slot) {
            foreach ($slot['rates'] as $rarity => $rate) {
                $rateSums[$rarity] = ($rateSums[$rarity] ?? 0.0) + $rate;
            }

            $holoSum += $slot['holoChance'];
        }

        return [
            'expectedShares' => array_map(static fn (float $sum): float => round($sum / $slotCount, 1), $rateSums),
            'expectedHoloRate' => round($holoSum / $slotCount, 1),
        ];
    }

    /**
     * Percentage (1 decimal) safe against an empty denominator.
     */
    private function share(int $count, int $total): float
    {
        return $total > 0 ? round($count / $total * 100, 1) : 0.0;
    }
}
