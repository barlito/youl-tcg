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
        $userBooster = $this->userBoosterRepository->findOneBy([
            'discordUser' => $discordUser,
            'booster' => $booster,
        ]);

        if (null === $userBooster) {
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
            throw new NoBoosterInInventoryException('You do not own this booster.');
        }

        return $userBooster->setQuantity($userBooster->getQuantity() - 1);
    }

    public function addCard(DiscordUser $discordUser, Card $card, int $quantity, int $holoQuantity = 0): UserCard
    {
        $userCard = $this->userCardRepository->findOneBy([
            'discordUser' => $discordUser,
            'card' => $card,
        ]);

        if (null === $userCard) {
            $userCard = new UserCard()
                ->setDiscordUser($discordUser)
                ->setCard($card)
            ;
            $this->entityManager->persist($userCard);
        }

        return $userCard
            ->setQuantity($userCard->getQuantity() + $quantity)
            ->setHoloQuantity($userCard->getHoloQuantity() + $holoQuantity)
        ;
    }
}
