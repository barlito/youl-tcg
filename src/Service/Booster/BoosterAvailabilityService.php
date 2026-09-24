<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;

/**
 * Single source of truth for booster availability: visibility, claim and open rules.
 */
final readonly class BoosterAvailabilityService
{
    public function __construct(
        private CardRepository $cardRepository,
    ) {
    }

    /**
     * @param array<string, int> $ownedCounts booster id => owned quantity
     */
    public function isVisible(Booster $booster, array $ownedCounts): bool
    {
        if ($booster->isClaimable()) {
            return true;
        }

        return ($ownedCounts[(string) $booster->getId()] ?? 0) > 0;
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

    public function isClaimable(Booster $booster): bool
    {
        return $booster->isClaimable();
    }

    public function hasPublishedExtension(Booster $booster): bool
    {
        return ExtensionStatusEnum::PUBLISHED === $booster->getExtension()->getStatus();
    }

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
     * Whether the booster can be distributed at all: published extension with
     * at least one drawable card. Codes use it as is (they reach non-claimable
     * boosters on purpose).
     */
    public function isDistributable(Booster $booster): bool
    {
        return $this->hasPublishedExtension($booster) && $this->isDrawable($booster);
    }

    /**
     * The single check behind every player-side booster retrieval (daily
     * claim, streak reward): claimable AND distributable.
     */
    public function isRetrievable(Booster $booster): bool
    {
        return $this->isClaimable($booster) && $this->isDistributable($booster);
    }

    /**
     * Batch variant of isRetrievable: one query for a whole booster list.
     *
     * @param list<Booster> $boosters
     *
     * @return array<string, true> booster id => true
     */
    public function retrievableBoosterIds(array $boosters): array
    {
        $claimable = array_filter(
            $boosters,
            fn (Booster $booster): bool => $this->isClaimable($booster) && $this->hasPublishedExtension($booster),
        );

        return $this->drawableBoosterIds(array_values($claimable));
    }

    /**
     * @param list<Booster> $boosters
     *
     * @return list<Booster>
     */
    public function filterRetrievable(array $boosters): array
    {
        $retrievable = $this->retrievableBoosterIds($boosters);

        return array_values(array_filter(
            $boosters,
            static fn (Booster $booster): bool => isset($retrievable[(string) $booster->getId()]),
        ));
    }

    /**
     * Opening deliberately ignores claimable and the extension status: a
     * player keeps the right to open a pack they own.
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
