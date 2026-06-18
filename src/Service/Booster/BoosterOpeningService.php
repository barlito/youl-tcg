<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Dto\DrawnCard;
use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\DiscordUser;
use App\Exception\Booster\NoBoosterInInventoryException;
use App\Exception\Booster\NoCardAvailableException;
use App\Repository\CardRepository;
use App\Service\Random\RandomService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Orchestrates a booster opening as one atomic transaction: debit the
 * booster from the inventory, draw the cards, credit them to the collection
 * and persist the audit trail with the RNG seed.
 */
final readonly class BoosterOpeningService
{
    public function __construct(
        private CardDrawer $cardDrawer,
        private UserInventoryService $userInventoryService,
        private RandomService $randomService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private CardRepository $cardRepository,
    ) {
    }

    /**
     * @throws NoBoosterInInventoryException
     * @throws NoCardAvailableException
     */
    public function open(DiscordUser $discordUser, Booster $booster): BoosterOpening
    {
        return $this->entityManager->wrapInTransaction(function () use ($discordUser, $booster): BoosterOpening {
            $this->userInventoryService->debitBooster($discordUser, $booster);

            $openedAt = $this->clock->now();
            $seed = crc32(\sprintf('%s-%s-%s', $discordUser->getDiscordId(), $booster->getId(), $openedAt->format('Uu')));
            $this->randomService->seed($seed);

            $opening = new BoosterOpening($discordUser, $booster, $seed, $openedAt);

            $drawnCards = $this->resolveUniqueClaims($discordUser, $booster, $this->cardDrawer->draw($booster));

            foreach ($this->aggregate($drawnCards) as $aggregated) {
                $this->userInventoryService->addCard($discordUser, $aggregated['card']->card, $aggregated['quantity'], $aggregated['holoQuantity']);
                $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $aggregated['card']->card, $aggregated['quantity'], $aggregated['holoQuantity']));
            }

            $this->entityManager->persist($opening);
            $this->entityManager->flush();

            return $opening;
        });
    }

    /**
     * Atomically claims each drawn one-of-one unique. A unique already taken by
     * a concurrent opening (or rolled twice in THIS booster) is swapped for a
     * replacement card of the same rarity, so a 1/1 is never credited twice.
     *
     * @param list<DrawnCard> $drawnCards
     *
     * @return list<DrawnCard>
     */
    private function resolveUniqueClaims(DiscordUser $discordUser, Booster $booster, array $drawnCards): array
    {
        $resolved = [];
        $claimedHere = [];

        foreach ($drawnCards as $drawnCard) {
            if (!$drawnCard->card->isUnique()) {
                $resolved[] = $drawnCard;

                continue;
            }

            $cardId = (string) $drawnCard->card->getId();
            $won = !isset($claimedHere[$cardId]) && $this->cardRepository->claimUnique($drawnCard->card, $discordUser);

            if ($won) {
                $claimedHere[$cardId] = true;
                $resolved[] = $drawnCard;
            } else {
                $resolved[] = $this->cardDrawer->drawReplacement($booster, $drawnCard->rarity);
            }
        }

        return $resolved;
    }

    /**
     * Aggregates duplicate cards: composite-keyed rows (BoosterOpeningCard,
     * UserCard) must be persisted once per card with summed quantities.
     *
     * @param list<DrawnCard> $drawnCards
     *
     * @return array<string, array{card: DrawnCard, quantity: int, holoQuantity: int}>
     */
    private function aggregate(array $drawnCards): array
    {
        $aggregated = [];

        foreach ($drawnCards as $drawnCard) {
            $cardId = (string) $drawnCard->card->getId();

            $aggregated[$cardId] ??= ['card' => $drawnCard, 'quantity' => 0, 'holoQuantity' => 0];
            ++$aggregated[$cardId]['quantity'];
            $aggregated[$cardId]['holoQuantity'] += $drawnCard->holo ? 1 : 0;
        }

        return $aggregated;
    }
}
