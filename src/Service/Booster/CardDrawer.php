<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\DrawnCard;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Exception\Booster\EmptyRarityRatesException;
use App\Exception\Booster\NoCardAvailableException;
use App\Repository\CardRepository;
use App\Service\Random\RandomService;

/**
 * Weighted card draw. Each booster slot first rolls its one-of-one chance,
 * then — unless a unique came out — rolls a rarity from its weight map and
 * picks a card uniformly among the published NON-unique cards of that rarity
 * in the booster's extension.
 *
 * Uniques are kept out of the rarity pool on purpose: their drop rate is the
 * slot's uniqueChance and nothing else, instead of drifting with the number
 * of cards published in their tier.
 */
final readonly class CardDrawer
{
    public function __construct(
        private CardRepository $cardRepository,
        private RandomService $randomService,
    ) {
    }

    /**
     * @throws EmptyRarityRatesException when the booster has no slot configured
     * @throws NoCardAvailableException  when the extension has no published card
     *
     * @return list<DrawnCard>
     */
    public function draw(Booster $booster): array
    {
        // Guard BEFORE any debit-side effect matters: with zero slots the loop
        // below would silently return no card while the caller already debited
        // the booster in the same transaction (the exception triggers rollback).
        if ([] === $booster->getRarityRates()) {
            throw new EmptyRarityRatesException(
                \sprintf('Booster "%s" has empty rarityRates: no slot to draw.', $booster->getDisplayName()),
                'Ce booster est mal configuré et ne peut pas être ouvert pour le moment, réessaie plus tard.',
            );
        }

        ['pool' => $pool, 'uniques' => $uniques] = $this->loadPool($booster->getExtension());

        if ([] === $pool && [] === $uniques) {
            throw new NoCardAvailableException(
                \sprintf('Extension "%s" has no published card to draw from.', $booster->getExtension()->getName()),
                'Ce booster n\'a aucune carte à tirer pour le moment, réessaie plus tard.',
            );
        }

        $drawnCards = [];

        foreach ($booster->getRarityRates() as $slot) {
            $uniqueIndex = $this->rollUnique($slot['uniqueChance'] ?? 0, $pool, $uniques);

            if (null !== $uniqueIndex) {
                // taken out of the local list so a second slot cannot land on
                // the very same 1/1 (it would be swapped out at claim time anyway)
                [$card] = array_splice($uniques, $uniqueIndex, 1);
                $rarity = $card->getRarity();
            } else {
                $rarity = $this->resolveAvailableRarity($pool, $this->drawRarity($slot['rarities']));
                $candidates = $pool[$rarity->value];
                $card = $candidates[$this->randomService->getInt(0, \count($candidates) - 1)];
            }

            $holo = $card->isAlwaysHolo() || $this->randomService->getInt(1, 100) <= $slot['holoChance'];

            $drawnCards[] = new DrawnCard($card, $rarity, $holo);
        }

        return $drawnCards;
    }

    /**
     * Index of the unique this slot pulls, or null for a regular rarity draw.
     *
     * A slot left at 0 never touches the RNG here. An extension whose only
     * drawable cards are uniques skips the chance roll — there is nothing
     * else left to hand out.
     *
     * @param array<string, non-empty-list<Card>> $pool
     * @param list<Card>                          $uniques
     */
    private function rollUnique(int $uniqueChance, array $pool, array $uniques): ?int
    {
        if ([] === $uniques) {
            return null;
        }

        if ([] !== $pool && ($uniqueChance <= 0 || $this->randomService->getInt(1, Booster::UNIQUE_CHANCE_SCALE) > $uniqueChance)) {
            return null;
        }

        return $this->randomService->getInt(0, \count($uniques) - 1);
    }

    /**
     * Draws a single replacement card of (or near) the given rarity, excluding
     * ALL unique cards. Used when a drawn unique was claimed by someone else in
     * a concurrent opening: the slot falls back to another card of the rarity,
     * KEEPING the holo the slot originally rolled (losing the unique must not
     * also cost a holo the booster config guaranteed).
     *
     * Rolls on the RNG side stream: whether a replacement happens depends on
     * concurrent claims, so it must not desync the seed-replay of the main draw.
     */
    public function drawReplacement(Booster $booster, CardRarityEnum $rarity, bool $holo = false): DrawnCard
    {
        ['pool' => $pool] = $this->loadPool($booster->getExtension());

        if ([] === $pool) {
            throw new NoCardAvailableException(
                \sprintf('Extension "%s" has no non-unique card for a replacement draw.', $booster->getExtension()->getName()),
                'Ce booster n\'a aucune carte à tirer pour le moment, réessaie plus tard.',
            );
        }

        $resolved = $this->resolveAvailableRarity($pool, $rarity);
        $candidates = $pool[$resolved->value];
        $card = $candidates[$this->randomService->getSideInt(0, \count($candidates) - 1)];

        return new DrawnCard($card, $resolved, $holo || $card->isAlwaysHolo());
    }

    /**
     * The drawable cards of the extension (published, claimed one-of-ones
     * filtered in SQL), split in two: the rarity pool, which never holds a
     * unique, and the unclaimed uniques the slots roll for separately.
     *
     * @return array{pool: array<string, non-empty-list<Card>>, uniques: list<Card>}
     */
    private function loadPool(Extension $extension): array
    {
        $pool = [];
        $uniques = [];

        foreach ($this->cardRepository->findDrawablePool($extension) as $card) {
            if ($card->isUnique()) {
                $uniques[] = $card;

                continue;
            }

            $pool[$card->getRarity()->value][] = $card;
        }

        return ['pool' => $pool, 'uniques' => $uniques];
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

        throw new NoCardAvailableException(
            'No rarity tier with available cards found in the pool.',
            'Ce booster n\'a aucune carte à tirer pour le moment, réessaie plus tard.',
        );
    }
}
