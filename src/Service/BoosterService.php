<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Enum\Entity\CardStatusEnum;
use App\Repository\BoosterOpeningRepository;
use App\Repository\CardRepository;
use App\Repository\UserCardRepository;
use Doctrine\ORM\EntityManagerInterface;

class BoosterService
{
    private const MAX_FREE_BOOSTERS_PER_DAY = 2;
    private const FREE_BOOSTER_RESET_HOURS = 24;

    public function __construct(
        private readonly BoosterOpeningRepository $boosterOpeningRepository,
        private readonly CardRepository $cardRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RandomService $randomService,
    ) {
    }

    /**
     * Check if a user can open a free booster.
     */
    public function canOpenBooster(DiscordUser $user): bool
    {
        return $this->getRemainingFreeOpenings($user) > 0;
    }

    /**
     * Get how many free booster openings the user has remaining.
     */
    public function getRemainingFreeOpenings(DiscordUser $user): int
    {
        $last24Hours = new \DateTimeImmutable('-' . self::FREE_BOOSTER_RESET_HOURS . ' hours');
        $count = $this->boosterOpeningRepository->countRecentOpenings($user, $last24Hours);

        return max(0, self::MAX_FREE_BOOSTERS_PER_DAY - $count);
    }

    /**
     * Get the time when the next free booster will be available.
     * Returns null if the user can open a booster now.
     */
    public function getNextAvailableTime(DiscordUser $user): ?\DateTimeImmutable
    {
        if ($this->canOpenBooster($user)) {
            return null;
        }

        // Find the oldest opening in the last 24 hours
        $oldestOpening = $this->boosterOpeningRepository->getOldestOpeningInLast24Hours($user);

        if (null === $oldestOpening) {
            return null;
        }

        // Next booster available 24h after the oldest opening
        return $oldestOpening->getOpenedAt()->modify('+' . self::FREE_BOOSTER_RESET_HOURS . ' hours');
    }

    /**
     * Open a booster for a user and return the cards obtained.
     *
     * @throws \RuntimeException if user cannot open booster
     *
     * @return Card[] Array of cards obtained
     */
    public function openBooster(DiscordUser $user, Booster $booster): array
    {
        if (!$this->canOpenBooster($user)) {
            throw new \RuntimeException('User has no free booster openings available');
        }

        // Wrap everything in a database transaction for atomicity
        $this->entityManager->beginTransaction();

        try {
            // Generate unique seed based on user, booster, and timestamp
            $seedString = \sprintf(
                '%s-%s-%d',
                $user->getDiscordId(),
                $booster->getId(),
                time(),
            );
            $seed = crc32($seedString);

            // Apply seed to random service
            $this->randomService->setSeed($seed);

            // Generate 3 random cards from the booster's extension with seeded RNG
            $cards = $this->generateRandomCards($booster, 3);

            if (empty($cards)) {
                throw new \RuntimeException('No cards available in this booster extension');
            }

            // Aggregate cards to handle duplicates (prevent duplicate key errors on UserCard)
            $cardQuantities = [];
            foreach ($cards as $card) {
                $cardId = $card->getId();
                if (!isset($cardQuantities[$cardId])) {
                    $cardQuantities[$cardId] = ['card' => $card, 'quantity' => 0];
                }
                ++$cardQuantities[$cardId]['quantity'];
            }

            // Add cards to user inventory (one query per unique card)
            foreach ($cardQuantities as $data) {
                $this->addCardToUserInventory($user, $data['card'], $data['quantity']);
            }

            // Create booster opening record
            $boosterOpening = new BoosterOpening();
            $boosterOpening->setDiscordUser($user);
            $boosterOpening->setBooster($booster);
            $boosterOpening->setSeed($seed);

            // Create BoosterOpeningCard entries for each unique card with quantity
            foreach ($cardQuantities as $data) {
                $boosterOpeningCard = new BoosterOpeningCard();
                $boosterOpeningCard->setCard($data['card']);
                $boosterOpeningCard->setQuantity($data['quantity']);
                $boosterOpening->addBoosterOpeningCard($boosterOpeningCard);
            }

            $this->entityManager->persist($boosterOpening);
            $this->entityManager->flush();

            // Commit transaction if everything succeeded
            $this->entityManager->commit();

            return $cards;
        } catch (\Exception $e) {
            // Rollback on any error to ensure data consistency
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Generate random cards from a booster's extension.
     *
     * @return Card[]
     */
    private function generateRandomCards(Booster $booster, int $count): array
    {
        $extension = $booster->getExtension();

        // Get all published cards from the extension
        $availableCards = $this->cardRepository->findBy([
            'extension' => $extension,
            'status' => CardStatusEnum::PUBLISHED,
        ]);

        if (empty($availableCards)) {
            return [];
        }

        $cards = [];
        $totalCards = \count($availableCards);

        for ($i = 0; $i < $count; ++$i) {
            // Pick a random card using seeded RNG (duplicates allowed)
            $randomIndex = $this->randomService->getRandomInt(0, $totalCards - 1);
            $cards[] = $availableCards[$randomIndex];
        }

        return $cards;
    }

    /**
     * Add a card to user's inventory (or increment quantity if already exists).
     */
    private function addCardToUserInventory(DiscordUser $user, Card $card, int $quantity = 1): void
    {
        $userCard = $this->userCardRepository->findOneBy([
            'discordUser' => $user,
            'card' => $card,
        ]);

        if (null === $userCard) {
            $userCard = new UserCard();
            $userCard->setDiscordUser($user);
            $userCard->setCard($card);
            $userCard->setQuantity($quantity);
            $this->entityManager->persist($userCard);
        } else {
            $userCard->setQuantity($userCard->getQuantity() + $quantity);
        }
    }
}
