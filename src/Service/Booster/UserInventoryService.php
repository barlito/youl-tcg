<?php

declare(strict_types=1);

namespace App\Service\Booster;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Exception\Booster\NoBoosterInInventoryException;
use App\Repository\UserBoosterRepository;
use App\Repository\UserCardRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create-or-increment mutations on the user's booster and card inventories.
 * Persists without flushing: callers own the transaction boundary.
 */
class UserInventoryService
{
    public function __construct(
        private readonly UserBoosterRepository $userBoosterRepository,
        private readonly UserCardRepository $userCardRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function creditBooster(DiscordUser $discordUser, Booster $booster, int $quantity = 1): UserBooster
    {
        // FOR UPDATE, like debitBooster: a credit racing an opening's debit on
        // the same row would read-modify-write over it (lost update — the
        // debit could be silently cancelled). Callers run in a transaction.
        $userBooster = $this->userBoosterRepository->findOneForUpdate($discordUser, $booster);

        if (!$userBooster instanceof UserBooster) {
            $userBooster = new UserBooster()
                ->setDiscordUser($discordUser)
                ->setBooster($booster)
            ;
            $this->entityManager->persist($userBooster);
        }

        return $userBooster->setQuantity($userBooster->getQuantity() + $quantity);
    }

    /**
     * @throws NoBoosterInInventoryException
     */
    public function debitBooster(DiscordUser $discordUser, Booster $booster): UserBooster
    {
        $userBooster = $this->userBoosterRepository->findOneForUpdate($discordUser, $booster);

        if (!$userBooster instanceof UserBooster || $userBooster->getQuantity() < 1) {
            throw new NoBoosterInInventoryException('You do not own this booster.', 'Tu ne possèdes pas ce booster.');
        }

        return $userBooster->setQuantity($userBooster->getQuantity() - 1);
    }

    public function addCard(DiscordUser $discordUser, Card $card, int $quantity, int $holoQuantity = 0): UserCard
    {
        return $this->addCards($discordUser, [['card' => $card, 'quantity' => $quantity, 'holoQuantity' => $holoQuantity]])[0];
    }

    /**
     * Credits several cards with a SINGLE inventory lookup (one `card IN (…)`
     * query) instead of one findOneBy per card — a booster opening credits its
     * whole draw at once.
     *
     * @param list<array{card: Card, quantity: int, holoQuantity: int}> $credits
     *
     * @return list<UserCard>
     */
    public function addCards(DiscordUser $discordUser, array $credits): array
    {
        $ownedRows = $this->userCardRepository->findBy([
            'discordUser' => $discordUser,
            'card' => array_map(static fn (array $credit): Card => $credit['card'], $credits),
        ]);

        $existing = [];
        foreach ($ownedRows as $userCard) {
            $existing[(string) $userCard->getCard()->getId()] = $userCard;
        }

        $userCards = [];
        foreach ($credits as $credit) {
            $userCard = $existing[(string) $credit['card']->getId()] ?? null;

            if (null === $userCard) {
                $userCard = new UserCard()
                    ->setDiscordUser($discordUser)
                    ->setCard($credit['card'])
                ;
                $this->entityManager->persist($userCard);
            }

            $userCards[] = $userCard
                ->setQuantity($userCard->getQuantity() + $credit['quantity'])
                ->setHoloQuantity($userCard->getHoloQuantity() + $credit['holoQuantity'])
            ;
        }

        return $userCards;
    }
}
