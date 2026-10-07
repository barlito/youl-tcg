<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use Doctrine\ORM\EntityManagerInterface;

trait DrawnCardTrait
{
    // records a pull in the player's own booster opening (the history only, no inventory change)
    private function recordDraw(EntityManagerInterface $entityManager, DiscordUser $user, Card $card): void
    {
        $booster = new Booster()
            ->setExtension($card->getExtension())
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $entityManager->persist($booster);
        $opening = new BoosterOpening($user, $booster, 42, new \DateTimeImmutable());
        $entityManager->persist($opening);
        $openingCard = new BoosterOpeningCard($opening, $card, 1, 0);
        $opening->addBoosterOpeningCard($openingCard);
        $entityManager->persist($openingCard);
        $entityManager->flush();
    }
}
