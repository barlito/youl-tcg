<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\CardRepository;
use App\Service\Booster\CardDrawer;
use App\Service\Random\RandomService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The RNG picks an index in the draw pool: the pool order must not depend on
 * the physical row order, or replaying an opening by its seed is unreliable.
 */
final class CardRepositoryDrawablePoolTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private CardRepository $cardRepository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->cardRepository = self::getContainer()->get(CardRepository::class);
    }

    public function testThePoolIsOrderedByIdWhateverTheUpdates(): void
    {
        [$extension, $cards] = $this->createExtensionWithCards(8);
        $this->touch(\array_slice($cards, 0, 4));

        $poolIds = array_map(
            static fn (Card $card): string => (string) $card->getId(),
            $this->cardRepository->findDrawablePool($extension),
        );

        $sortedIds = $poolIds;
        sort($sortedIds, \SORT_STRING);

        $this->assertCount(8, $poolIds);
        $this->assertSame($sortedIds, $poolIds);
    }

    public function testASeededDrawIsReproducibleOnAnUnchangedPool(): void
    {
        [$extension, $cards] = $this->createExtensionWithCards(8);
        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates(array_fill(0, 10, ['rarities' => ['common' => 100], 'holoChance' => 50]))
        ;

        $first = $this->drawWithSeed($booster, 1234);

        // rewrites rows without changing the pool contents
        $this->touch(array_reverse($cards));

        $this->assertSame($first, $this->drawWithSeed($booster, 1234));
    }

    /**
     * @return list<string> card id + holo flag per slot
     */
    private function drawWithSeed(Booster $booster, int $seed): array
    {
        $randomService = new RandomService();
        $randomService->seed($seed);

        return array_map(
            static fn ($drawnCard): string => $drawnCard->card->getId() . ($drawnCard->holo ? ':holo' : ''),
            new CardDrawer($this->cardRepository, $randomService)->draw($booster),
        );
    }

    /**
     * @return array{Extension, list<Card>}
     */
    private function createExtensionWithCards(int $count): array
    {
        $extension = new Extension()
            ->setName('Pool order test ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        $cards = [];
        for ($i = 0; $i < $count; ++$i) {
            $card = new Card()
                ->setName('Pool card ' . $i)
                ->setDescription('Test card')
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity(CardRarityEnum::COMMON)
                ->setExtension($extension)
            ;
            $card->setImageName('default_card.png');
            $this->entityManager->persist($card);
            $cards[] = $card;
        }

        $this->entityManager->flush();

        return [$extension, $cards];
    }

    /**
     * @param list<Card> $cards
     */
    private function touch(array $cards): void
    {
        foreach ($cards as $card) {
            $card->setDescription('Updated ' . uniqid());
            $this->entityManager->flush();
        }

        $this->entityManager->clear();
    }
}
