<?php

declare(strict_types=1);

namespace App\Service\Wishlist;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\WishlistEntry;
use App\Entity\WishlistUniverse;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Wishlist\WishlistRefusedException;
use App\Repository\ExtensionRepository;
use App\Repository\MarketListingRepository;
use App\Repository\UserCardRepository;
use App\Repository\WishlistEntryRepository;
use App\Repository\WishlistUniverseRepository;
use App\Service\Feature\FeatureFlags;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Direct wishes (a named card) and universe watches (« my missing cards of this
 * universe »). Masking rule: a card can only be wished from a place where the
 * player already sees it in clear — owned now, held once, or on an active
 * market listing. A card they never saw is refused, whatever the caller.
 */
final readonly class WishlistService
{
    public const int MAX_ENTRIES = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WishlistEntryRepository $entryRepository,
        private WishlistUniverseRepository $universeRepository,
        private UserCardRepository $userCardRepository,
        private MarketListingRepository $listingRepository,
        private ExtensionRepository $extensionRepository,
        private FeatureFlags $featureFlags,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->featureFlags->isEnabled(FeatureEnum::WISHLIST);
    }

    /** @throws WishlistRefusedException */
    public function add(DiscordUser $player, Card $card): WishlistEntry
    {
        $this->assertEnabled();

        if (!$this->canSee($player, $card)) {
            throw new WishlistRefusedException(\sprintf('Card %s is not visible to %s.', $card->getId(), $player->getDiscordId()), 'Cette carte n\'est pas disponible.');
        }

        // the cap is a COUNT then an INSERT: serialize the player's changes on their row
        $result = $this->entityManager->wrapInTransaction(function () use ($player, $card): WishlistEntry | WishlistRefusedException {
            $this->entityManager->find(DiscordUser::class, $player->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

            $existing = $this->entryRepository->findOneFor($player, $card);
            if ($existing instanceof WishlistEntry) {
                return $existing;
            }

            if ($this->entryRepository->countForPlayer($player) >= self::MAX_ENTRIES) {
                return new WishlistRefusedException('Wishlist cap reached.', \sprintf('Ta wishlist est pleine (%d cartes) : retires-en une avant d\'en ajouter.', self::MAX_ENTRIES));
            }

            $entry = new WishlistEntry($player, $card);
            $this->entityManager->persist($entry);
            $this->entityManager->flush();

            return $entry;
        });

        if ($result instanceof WishlistRefusedException) {
            throw $result;
        }

        return $result;
    }

    /** @throws WishlistRefusedException */
    public function remove(DiscordUser $player, Card $card): void
    {
        $this->assertEnabled();
        $this->entryRepository->deleteForCards($player, [(string) $card->getId()]);
    }

    /** @throws WishlistRefusedException */
    public function removeEntry(DiscordUser $player, string $entryId): void
    {
        $this->assertEnabled();
        // client-provided: a malformed id is "not found", never a conversion error
        $entry = Uuid::isValid($entryId) ? $this->entryRepository->find($entryId) : null;

        if ($entry instanceof WishlistEntry && $entry->getPlayer()->getDiscordId() === $player->getDiscordId()) {
            $this->entityManager->remove($entry);
            $this->entityManager->flush();
        }
    }

    /**
     * @throws WishlistRefusedException
     *
     * @return bool true when the card is now wished
     */
    public function toggle(DiscordUser $player, Card $card): bool
    {
        $this->assertEnabled();

        if ($this->entryRepository->findOneFor($player, $card) instanceof WishlistEntry) {
            $this->remove($player, $card);

            return false;
        }

        $this->add($player, $card);

        return true;
    }

    /** @throws WishlistRefusedException */
    public function watchUniverse(DiscordUser $player, Extension $extension): void
    {
        $this->assertEnabled();

        if (ExtensionStatusEnum::PUBLISHED !== $extension->getStatus()) {
            throw new WishlistRefusedException(\sprintf('Extension %s is not published.', $extension->getId()), 'Cet univers n\'est pas disponible.');
        }

        $this->entityManager->wrapInTransaction(function () use ($player, $extension): void {
            $this->entityManager->find(DiscordUser::class, $player->getDiscordId(), LockMode::PESSIMISTIC_WRITE);

            if (!$this->universeRepository->findOneFor($player, $extension) instanceof WishlistUniverse) {
                $this->entityManager->persist(new WishlistUniverse($player, $extension));
                $this->entityManager->flush();
            }
        });
    }

    /** @throws WishlistRefusedException */
    public function unwatchUniverse(DiscordUser $player, Extension $extension): void
    {
        $this->assertEnabled();
        $watch = $this->universeRepository->findOneFor($player, $extension);

        if ($watch instanceof WishlistUniverse) {
            $this->entityManager->remove($watch);
            $this->entityManager->flush();
        }
    }

    /**
     * @throws WishlistRefusedException
     *
     * @return bool true when the universe is now watched
     */
    public function toggleUniverse(DiscordUser $player, Extension $extension): bool
    {
        $this->assertEnabled();

        if ($this->isWatching($player, $extension)) {
            $this->unwatchUniverse($player, $extension);

            return false;
        }

        $this->watchUniverse($player, $extension);

        return true;
    }

    public function isWatching(DiscordUser $player, Extension $extension): bool
    {
        return $this->universeRepository->findOneFor($player, $extension) instanceof WishlistUniverse;
    }

    /** @return array<string, true> card id => wished directly */
    public function wishedCardIds(DiscordUser $player): array
    {
        return $this->isEnabled() ? $this->entryRepository->findWishedCardIds($player) : [];
    }

    /**
     * A card is visible in clear when it is a published card the player owns
     * or held once, or one on sale on the market (listings are public).
     */
    public function canSee(DiscordUser $player, Card $card): bool
    {
        if (CardStatusEnum::PUBLISHED !== $card->getStatus() || ExtensionStatusEnum::PUBLISHED !== $card->getExtension()?->getStatus()) {
            return false;
        }

        return isset($this->userCardRepository->findEverOwnedCardIds($player)[(string) $card->getId()])
            || $this->listingRepository->existsActiveForCard($card);
    }

    /**
     * The wishes of the player, each flagged with whether the card may be shown
     * in clear right now (otherwise it stays a card back) and whether it is on
     * sale at the moment.
     *
     * @return list<array{entry: WishlistEntry, visible: bool, listed: bool}>
     */
    public function listEntries(DiscordUser $player): array
    {
        $entries = $this->entryRepository->findForPlayer($player);
        $known = $this->userCardRepository->findEverOwnedCardIds($player);
        $listed = $this->listingRepository->findActiveCardIds(array_map(static fn (WishlistEntry $entry): string => (string) $entry->getCard()->getId(), $entries));

        return array_map(static function (WishlistEntry $entry) use ($known, $listed): array {
            $id = (string) $entry->getCard()->getId();

            return ['entry' => $entry, 'visible' => isset($known[$id]) || isset($listed[$id]), 'listed' => isset($listed[$id])];
        }, $entries);
    }

    /**
     * @return list<array{universe: WishlistUniverse, missing: int}>
     */
    public function listUniverses(DiscordUser $player): array
    {
        $owned = $this->userCardRepository->countOwnedGroupedByExtension($player);
        $totals = [];
        foreach ($this->extensionRepository->findPublishedWithPublishedCardCount() as $item) {
            $totals[(string) $item['extension']->getId()] = $item['cardCount'];
        }

        return array_map(static function (WishlistUniverse $watch) use ($owned, $totals): array {
            $id = (string) $watch->getExtension()->getId();

            return ['universe' => $watch, 'missing' => max(0, ($totals[$id] ?? 0) - ($owned[$id] ?? 0))];
        }, $this->universeRepository->findForPlayer($player));
    }

    /**
     * Cards among $cardIds the player is looking for (wished, or missing in a
     * watched universe). Only meant for cards the viewer already owns.
     *
     * @param list<string> $cardIds
     *
     * @return array<string, true>
     */
    public function wantedAmong(DiscordUser $wanter, array $cardIds): array
    {
        return $this->isEnabled() ? $this->entryRepository->findWantedAmong($wanter, $cardIds) : [];
    }

    /**
     * Post-commit cleanup once the player obtained these cards: the direct
     * wish is satisfied, so it is dropped. Best effort, never thrown.
     *
     * @param iterable<Card> $cards
     */
    public function fulfil(DiscordUser $player, iterable $cards): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }

            $ids = [];
            foreach ($cards as $card) {
                $ids[(string) $card->getId()] = (string) $card->getId();
            }

            $this->entryRepository->deleteForCards($player, array_values($ids));
        } catch (\Throwable $exception) {
            $this->logger->error('Wishlist cleanup failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    /** @throws WishlistRefusedException */
    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new WishlistRefusedException('Wishlist feature is disabled.', 'La wishlist est momentanément fermée.');
        }
    }
}
