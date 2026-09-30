<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardRepositoryDrawableRaritiesTest extends KernelTestCase
{
    public function testOnlyPublishedNonUniqueRaritiesAreReachableByTheRarityRoll(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()->setName('Rarities test ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $entityManager->persist($extension);

        $specs = [
            [CardRarityEnum::COMMON, CardStatusEnum::PUBLISHED, false],
            [CardRarityEnum::COMMON, CardStatusEnum::PUBLISHED, false],
            [CardRarityEnum::RARE, CardStatusEnum::DRAFT, false],
            [CardRarityEnum::LEGENDARY, CardStatusEnum::PUBLISHED, true],
        ];

        foreach ($specs as [$rarity, $status, $unique]) {
            $card = new Card()->setName('Card ' . uniqid())->setDescription('Test')->setStatus($status)->setRarity($rarity)->setUnique($unique)->setExtension($extension);
            $card->setImageName('default_card.png');
            $entityManager->persist($card);
        }

        $entityManager->flush();

        $rarities = self::getContainer()->get(CardRepository::class)->findDrawableRaritiesByExtension();

        $this->assertSame(['common'], $rarities[(string) $extension->getId()]);
    }
}
