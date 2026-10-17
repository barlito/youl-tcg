<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Duel catalogue built per test: a published universe, its cards and who owns them.
 */
trait DuelTestTrait
{
    private function duelExtension(ExtensionStatusEnum $status = ExtensionStatusEnum::PUBLISHED): Extension
    {
        $extension = new Extension()->setName('Duel ' . uniqid())->setDescription('Duel test')->setStatus($status);
        $this->duelEntityManager()->persist($extension);

        return $extension;
    }

    /**
     * @param list<string> $tags
     */
    private function duelCard(Extension $extension, string $name, bool $terrain = false, array $tags = [], CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $card = new Card()
            ->setName($name)
            ->setDescription('Duel test card')
            ->setRarity(CardRarityEnum::COMMON)
            ->setStatus($status)
            ->setExtension($extension)
            ->setTerrain($terrain)
            ->setTags($tags)
        ;
        $this->duelEntityManager()->persist($card);

        return $card;
    }

    private function give(DiscordUser $user, Card $card, int $quantity = 1, int $holoQuantity = 0): UserCard
    {
        $userCard = new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity($holoQuantity);
        $this->duelEntityManager()->persist($userCard);

        return $userCard;
    }

    /**
     * @return list<Card> $count published playable cards, all owned by $user
     */
    private function ownedCards(DiscordUser $user, Extension $extension, int $count = 12): array
    {
        $cards = [];
        for ($i = 1; $i <= $count; ++$i) {
            $cards[] = $card = $this->duelCard($extension, \sprintf('Duel card %02d', $i));
            $this->give($user, $card);
        }
        $this->duelEntityManager()->flush();

        return $cards;
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<string>
     */
    private function ids(array $cards): array
    {
        return array_map(static fn (Card $card): string => (string) $card->getId(), $cards);
    }

    private function duelEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
