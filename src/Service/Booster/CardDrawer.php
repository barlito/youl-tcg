<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\DrawnCard;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Exception\Booster\NoCardAvailableException;
use App\Repository\CardRepository;
use App\Service\Random\RandomService;

/**
 * Weighted card draw. Each booster slot rolls a rarity from its weight map,
 * then picks a card uniformly among the published cards of that rarity in
 * the booster's extension.
 */
final readonly class CardDrawer
{
    public function __construct(
        private CardRepository $cardRepository,
        private RandomService $randomService,
    ) {
    }

    /**
     * @throws NoCardAvailableException when the extension has no published card
     *
     * @return list<DrawnCard>
     */
    public function draw(Booster $booster): array
    {
        $pool = $this->loadPool($booster->getExtension());

        if ([] === $pool) {
            throw new NoCardAvailableException(\sprintf('Extension "%s" has no published card to draw from.', $booster->getExtension()->getName()));
        }

        $drawnCards = [];

        foreach ($booster->getRarityRates() as $slot) {
            $rarity = $this->resolveAvailableRarity($pool, $this->drawRarity($slot['rarities']));
            $candidates = $pool[$rarity->value];
            $card = $candidates[$this->randomService->getInt(0, \count($candidates) - 1)];
            $holo = $card->isAlwaysHolo() || $this->randomService->getInt(1, 100) <= $slot['holoChance'];

            $drawnCards[] = new DrawnCard($card, $rarity, $holo);
        }

        return $drawnCards;
    }

    /**
     * @return array<string, non-empty-list<Card>> published cards grouped by rarity value
     */
    private function loadPool(Extension $extension): array
    {
        $cards = $this->cardRepository->findBy([
            'extension' => $extension,
            'status' => CardStatusEnum::PUBLISHED,
        ]);

        $pool = [];

        foreach ($cards as $card) {
            $pool[$card->getRarity()->value][] = $card;
        }

        return $pool;
    }

    /**
     * Cumulative weighted roll over a slot's rarity weight map.
     *
     * @param array<string, int> $weights
     */
    private function drawRarity(array $weights): CardRarityEnum
    {
        if ([] === $weights) {
            throw new \InvalidArgumentException('A booster slot must define at least one rarity weight.');
        }

        $roll = $this->randomService->getInt(1, array_sum($weights));

        foreach ($weights as $rarity => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return CardRarityEnum::from((string) $rarity);
            }
        }

        throw new \LogicException('Weighted roll exhausted the weight map without resolving a rarity.');
    }

    /**
     * Falls back to the closest rarity that actually has cards in the pool:
     * first toward common, then toward legendary.
     *
     * @param array<string, non-empty-list<Card>> $pool
     */
    private function resolveAvailableRarity(array $pool, CardRarityEnum $rolled): CardRarityEnum
    {
        $ascending = CardRarityEnum::ascending();
        $rolledIndex = (int) array_search($rolled, $ascending, true);

        for ($index = $rolledIndex; $index >= 0; --$index) {
            if (isset($pool[$ascending[$index]->value])) {
                return $ascending[$index];
            }
        }
        $counter = \count($ascending);

        for ($index = $rolledIndex + 1; $index < $counter; ++$index) {
            if (isset($pool[$ascending[$index]->value])) {
                return $ascending[$index];
            }
        }

        throw new NoCardAvailableException('No rarity tier with available cards found in the pool.');
    }
}
