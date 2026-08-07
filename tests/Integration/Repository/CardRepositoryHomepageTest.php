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

/**
 * The homepage showcase must never put a one-of-one on display: it would spoil
 * a card nobody may have drawn yet, while the universe and opening pages go out
 * of their way to mask exactly that.
 */
final class CardRepositoryHomepageTest extends KernelTestCase
{
    public function testTheShowcaseDrawsRegularCardsButNeverAUnique(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Showcase test ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $regular = $this->card($extension, 'Régulière');
        $legendary = $this->card($extension, 'Légendaire')->setRarity(CardRarityEnum::LEGENDARY);
        $unique = $this->card($extension, 'Unique')->setUnique(true);

        foreach ([$regular, $legendary, $unique] as $card) {
            $entityManager->persist($card);
        }
        $entityManager->flush();

        // ask for far more than the catalogue holds: the draw is random, so the
        // assertion only means something once every eligible card is returned
        $drawn = self::getContainer()->get(CardRepository::class)->findRandomCardId(5000);
        $drawn = array_map(strval(...), $drawn);

        $this->assertContains((string) $regular->getId(), $drawn);
        $this->assertContains(
            (string) $legendary->getId(),
            $drawn,
            'Legendaries stay in: they exist in several copies and are the point of the showcase.',
        );
        $this->assertNotContains((string) $unique->getId(), $drawn);
    }

    private function card(Extension $extension, string $name): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');

        return $card;
    }
}
