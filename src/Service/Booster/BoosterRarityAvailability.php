<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\CardRepository;

/**
 * Tells which rarities a booster weights while its extension cannot deliver
 * them. CardDrawer silently falls back to the nearest tier in that case, so
 * the back-office warns instead of letting the misconfiguration go unnoticed.
 *
 * Availability is read from the very pool the draw uses (published cards,
 * claimed one-of-ones excluded) and memoised per extension: an admin index
 * lists many boosters sharing a handful of extensions.
 */
final class BoosterRarityAvailability
{
    /** @var array<string, list<string>> */
    private array $drawableRaritiesByExtension = [];

    public function __construct(private readonly CardRepository $cardRepository)
    {
    }

    /**
     * @return list<CardRarityEnum> weighted rarities with no drawable card, rarest last
     */
    public function findUnavailableRarities(Booster $booster): array
    {
        if (!$booster->hasExtension()) {
            return [];
        }

        $drawable = $this->drawableRarities($booster->getExtension());
        $unavailable = [];

        foreach (CardRarityEnum::ascending() as $rarity) {
            if (\in_array($rarity->value, $drawable, true)) {
                continue;
            }

            if ($this->isWeighted($booster, $rarity)) {
                $unavailable[] = $rarity;
            }
        }

        return $unavailable;
    }

    private function isWeighted(Booster $booster, CardRarityEnum $rarity): bool
    {
        return array_any(
            $booster->getRarityRates(),
            static fn (array $slot): bool => \array_key_exists($rarity->value, $slot['rarities']),
        );
    }

    /**
     * @return list<string>
     */
    private function drawableRarities(Extension $extension): array
    {
        $key = $extension->getId() ?? 'oid:' . spl_object_id($extension);

        if (!isset($this->drawableRaritiesByExtension[$key])) {
            $rarities = [];

            foreach ($this->cardRepository->findDrawablePool($extension) as $card) {
                $rarities[$card->getRarity()->value] = true;
            }

            $this->drawableRaritiesByExtension[$key] = array_keys($rarities);
        }

        return $this->drawableRaritiesByExtension[$key];
    }
}
