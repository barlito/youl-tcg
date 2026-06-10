<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\NoBoosterInInventoryException;
use App\Exception\Booster\NoCardAvailableException;
use App\Service\Booster\BoosterOpeningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoosterOpeningServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BoosterOpeningService $openingService;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->openingService = self::getContainer()->get(BoosterOpeningService::class);
    }

    public function testOpeningDebitsInventoryAndCreditsCards(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 2);

        $opening = $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity());

        $userCards = $this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertSame(3, array_sum(array_map(static fn (UserCard $userCard): int => $userCard->getQuantity(), $userCards)));

        $persistedOpening = $this->entityManager->getRepository(BoosterOpening::class)->find($opening->getId());
        $this->assertNotNull($persistedOpening);
        $this->assertNotSame(0, $persistedOpening->getSeed());
        $this->assertSame(
            3,
            array_sum($persistedOpening->getBoosterOpeningCards()->map(static fn ($openingCard): int => $openingCard->getQuantity())->toArray()),
        );
    }

    public function testSecondOpeningIncrementsExistingUserCards(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 2, rarityRates: [['common' => 100]]);

        $this->openingService->open($scenario['user'], $scenario['booster']);
        $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->entityManager->clear();

        $userCards = $this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertCount(1, $userCards);
        $this->assertSame(2, $userCards[0]->getQuantity());
    }

    public function testFullHoloRateCreditsHoloQuantities(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1, holoRate: 100, rarityRates: [['common' => 100], ['common' => 100]]);

        $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->entityManager->clear();

        $userCards = $this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertCount(1, $userCards);
        $this->assertSame(2, $userCards[0]->getQuantity());
        $this->assertSame(2, $userCards[0]->getHoloQuantity());
    }

    public function testZeroHoloRateCreditsNoHolo(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1, holoRate: 0);

        $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->entityManager->clear();

        foreach ($this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]) as $userCard) {
            $this->assertSame(0, $userCard->getHoloQuantity());
        }
    }

    public function testOpeningWithoutInventoryThrowsAndChangesNothing(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 0);

        try {
            $this->openingService->open($scenario['user'], $scenario['booster']);
            $this->fail('Expected NoBoosterInInventoryException');
        } catch (NoBoosterInInventoryException) {
        }

        $this->entityManager->clear();

        $this->assertSame([], $this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]));
        $this->assertSame([], $this->entityManager->getRepository(BoosterOpening::class)->findBy(['discordUser' => $scenario['user']]));
    }

    public function testOpeningRollsBackWhenExtensionHasNoPublishedCard(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1, withPublishedCards: false);

        try {
            $this->openingService->open($scenario['user'], $scenario['booster']);
            $this->fail('Expected NoCardAvailableException');
        } catch (NoCardAvailableException) {
        }

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $scenario['user']]);
        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity(), 'The booster must not be consumed when the draw fails.');
        $this->assertSame([], $this->entityManager->getRepository(BoosterOpening::class)->findBy(['discordUser' => $scenario['user']]));
    }

    /**
     * @param list<array<string, int>>|null $rarityRates
     *
     * @return array{user: DiscordUser, booster: Booster}
     */
    private function createScenario(
        int $boosterQuantity,
        int $holoRate = 10,
        ?array $rarityRates = null,
        bool $withPublishedCards = true,
    ): array {
        $extension = new Extension()
            ->setName('Opening test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        if ($withPublishedCards) {
            $cardDefinitions = [
                ['Common test card', CardRarityEnum::COMMON],
                ['Rare test card', CardRarityEnum::RARE],
                ['Legendary test card', CardRarityEnum::LEGENDARY],
            ];

            foreach ($cardDefinitions as [$name, $rarity]) {
                $card = new Card()
                    ->setName($name)
                    ->setDescription('Test card')
                    ->setStatus(CardStatusEnum::PUBLISHED)
                    ->setRarity($rarity)
                    ->setExtension($extension)
                ;
                $card->setImageName('default_card.png');
                $this->entityManager->persist($card);
            }
        }

        $booster = new Booster()
            ->setExtension($extension)
            ->setHoloRate($holoRate)
            ->setRarityRates($rarityRates ?? [['common' => 100], ['common' => 100], ['common' => 60, 'rare' => 30, 'legendary' => 10]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $user = new DiscordUser()
            ->setDiscordId('opening-test-' . uniqid())
            ->setUsername('Opening tester')
        ;
        $this->entityManager->persist($user);

        if ($boosterQuantity > 0) {
            $userBooster = new UserBooster()
                ->setDiscordUser($user)
                ->setBooster($booster)
                ->setQuantity($boosterQuantity)
            ;
            $this->entityManager->persist($userBooster);
        }

        $this->entityManager->flush();

        return ['user' => $user, 'booster' => $booster];
    }
}
