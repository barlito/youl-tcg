<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;

trait MarketTestTrait
{
    private EntityManagerInterface $entityManager;

    private Extension $extension;

    private function bootMarketFixtures(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->extension = new Extension()->setName('Market extension ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($this->extension);
        // a second published card nobody owns: a purchase never completes the universe by accident
        $this->createCard('Filler');
    }

    private function createUser(string $prefix): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId($prefix . '-' . uniqid())->setUsername(ucfirst($prefix));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createCard(string $name, bool $unique = false, ?DiscordUser $claimedBy = null): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setUnique($unique)
            ->setClaimedBy($claimedBy)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function giveCards(DiscordUser $user, Card $card, int $quantity, int $holoQuantity = 0): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity($holoQuantity));
        $this->entityManager->flush();
    }

    /**
     * @return array{int, int} quantity (holos included), holo quantity
     */
    private function owned(DiscordUser $user, Card $card): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT quantity, holo_quantity FROM user_card WHERE discord_user_id = ? AND card_id = ?',
            [$user->getDiscordId(), (string) $card->getId()],
        );

        return false === $row ? [0, 0] : [(int) $row['quantity'], (int) $row['holo_quantity']];
    }
}
