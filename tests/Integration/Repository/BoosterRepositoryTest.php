<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoosterRepositoryTest extends KernelTestCase
{
    public function testFindPublishedOrderIsDeterministicAcrossBoostersOfTheSameExtension(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('AA sort test ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        // one unnamed (sorts as the extension name) + two named boosters
        foreach ([null, 'ZZ Pack Rare', 'AA Pack Doux'] as $name) {
            $booster = new Booster()->setExtension($extension)->setName($name)
                ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
            ;
            $booster->setImageName('default_card.png');
            $entityManager->persist($booster);
        }
        $entityManager->flush();

        $repository = self::getContainer()->get(BoosterRepository::class);
        $names = static fn (array $boosters): array => array_map(
            static fn (Booster $booster): string => $booster->getDisplayName(),
            array_values(array_filter($boosters, static fn (Booster $b): bool => $b->getExtension() === $extension)),
        );

        $first = $names($repository->findPublished());
        // display name ascending, whatever the persist order was
        $this->assertSame(['AA Pack Doux', $extension->getName(), 'ZZ Pack Rare'], $first);
        // and identical on a second run (id tiebreak: no per-request reshuffle)
        $this->assertSame($first, $names($repository->findPublished()));
    }
}
