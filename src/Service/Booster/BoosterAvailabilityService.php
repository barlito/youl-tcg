<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;

/**
 * Single source of truth for booster availability: which boosters a user can
 * see, claim and open. Controllers, components and services must go through
 * these predicates instead of re-implementing the rules locally.
 */
final readonly class BoosterAvailabilityService
{
    public function __construct(
        private CardRepository $cardRepository,
    ) {
    }

    /**
     * Hub/universe visibility: a non-claimable booster (event/code
     * distribution) is hidden from everyone except the users who already own
     * copies — they still need to see it (and its drop rates) to open theirs.
     *
     * @param array<string, int> $ownedCounts booster id => owned quantity
     */
    public function isVisible(Booster $booster, array $ownedCounts): bool
    {
        return $booster->isClaimable() || ($ownedCounts[(string) $booster->getId()] ?? 0) > 0;
    }

    /**
     * @param list<Booster>      $boosters
     * @param array<string, int> $ownedCounts booster id => owned quantity
     *
     * @return list<Booster>
     */
    public function filterVisible(array $boosters, array $ownedCounts): array
    {
        return array_values(array_filter(
            $boosters,
            fn (Booster $booster): bool => $this->isVisible($booster, $ownedCounts),
        ));
    }

    /**
     * Claim guard, first half: an event/code-only booster cannot be claimed
     * on the hub, whoever asks.
     */
    public function isClaimable(Booster $booster): bool
    {
        return $booster->isClaimable();
    }

    /**
     * Claim guard, second half: a booster over an unpublished extension is
     * not available at all (its uuid can leak, it must stay unclaimable).
     */
    public function hasPublishedExtension(Booster $booster): bool
    {
        return ExtensionStatusEnum::PUBLISHED === $booster->getExtension()->getStatus();
    }

    /**
     * A booster is drawable when its extension has at least one published,
     * still drawable card (a claimed 1/1 unique doesn't count): otherwise it
     * is surfaced as "à venir" instead of letting the user hit a draw error.
     */
    public function isDrawable(Booster $booster): bool
    {
        return isset($this->drawableExtensionIds()[(string) $booster->getExtension()->getId()]);
    }

    /**
     * Batch variant of isDrawable: one query for a whole booster list.
     *
     * @param list<Booster> $boosters
     *
     * @return array<string, true> booster id => true
     */
    public function drawableBoosterIds(array $boosters): array
    {
        $extensionIds = $this->drawableExtensionIds();

        $drawable = [];
        foreach ($boosters as $booster) {
            if (isset($extensionIds[(string) $booster->getExtension()->getId()])) {
                $drawable[(string) $booster->getId()] = true;
            }
        }

        return $drawable;
    }

    /**
     * A booster can be opened when it is drawable AND the user owns at least
     * one unopened copy.
     */
    public function isOpenable(Booster $booster, int $ownedQuantity): bool
    {
        return $ownedQuantity >= 1 && $this->isDrawable($booster);
    }

    /**
     * @return array<string, int> extension id => index (flipped id list, for O(1) lookups)
     */
    private function drawableExtensionIds(): array
    {
        return array_flip($this->cardRepository->findExtensionIdsWithPublishedCards());
    }
}
