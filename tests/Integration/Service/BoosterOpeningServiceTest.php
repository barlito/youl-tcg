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

        $result = $this->openingService->open($scenario['user'], $scenario['booster']);
        $opening = $result->opening;

        // the result also exposes the raw draw (slot order) for the reveal
        $this->assertCount(3, $result->drawnCards);

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

    public function testDrawnCardsFollowTheSlotOrder(): void
    {
        // slot 1 forces a legendary, slot 2 a common: the exposed draw must
        // keep that order (a rarest-last re-sort would flip it) — the reveal
        // mirrors the per-slot rates the player reads on the pack
        $scenario = $this->createScenario(
            boosterQuantity: 1,
            rarityRates: [['legendary' => 100], ['common' => 100]],
        );

        $result = $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->assertSame(
            [CardRarityEnum::LEGENDARY, CardRarityEnum::COMMON],
            array_map(static fn ($drawnCard) => $drawnCard->card->getRarity(), $result->drawnCards),
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

    public function testFullHoloChanceCreditsHoloQuantities(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1, holoChance: 100, rarityRates: [['common' => 100], ['common' => 100]]);

        $this->openingService->open($scenario['user'], $scenario['booster']);

        $this->entityManager->clear();

        $userCards = $this->entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $scenario['user']]);
        $this->assertCount(1, $userCards);
        $this->assertSame(2, $userCards[0]->getQuantity());
        $this->assertSame(2, $userCards[0]->getHoloQuantity());
    }

    public function testZeroHoloChanceCreditsNoHolo(): void
    {
        $scenario = $this->createScenario(boosterQuantity: 1, holoChance: 0);

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

    public function testUniqueCardIsClaimedByFirstOpenerAndNeverDrawnAgain(): void
    {
        $extension = new Extension()
            ->setName('Unique test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        // The only RARE card is a one-of-one; a filler COMMON gives the draw a
        // fallback once the unique is claimed. The 1/1 is out of the rarity
        // pool: only the slot's uniqueChance can hand it out.
        $unique = new Card()
            ->setName('One of one')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::RARE)
            ->setExtension($extension)
            ->setUnique(true)
        ;
        $unique->setImageName('default_card.png');
        $this->entityManager->persist($unique);

        $filler = new Card()
            ->setName('Filler common')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $filler->setImageName('default_card.png');
        $this->entityManager->persist($filler);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['rare' => 100], 'holoChance' => 0, 'uniqueChance' => Booster::UNIQUE_CHANCE_SCALE]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $alice = new DiscordUser()->setDiscordId('uniq-alice-' . uniqid())->setUsername('Alice');
        $bob = new DiscordUser()->setDiscordId('uniq-bob-' . uniqid())->setUsername('Bob');
        foreach ([$alice, $bob] as $user) {
            $this->entityManager->persist($user);
            $this->entityManager->persist(new UserBooster()->setDiscordUser($user)->setBooster($booster)->setQuantity(1));
        }
        $this->entityManager->flush();

        // Alice opens first: the slot always rolls its unique chance, draws the
        // only unclaimed 1/1 and claims it.
        $this->openingService->open($alice, $booster);
        // Bob opens next: the claimed unique is filtered out of the pool, so the
        // slot falls back to the rarity draw — Bob can never get the 1/1.
        $this->openingService->open($bob, $booster);

        $this->entityManager->clear();

        $claimed = $this->entityManager->getRepository(Card::class)->find($unique->getId());
        $this->assertNotNull($claimed?->getClaimedBy());
        $this->assertSame($alice->getDiscordId(), $claimed->getClaimedBy()->getDiscordId());

        $this->assertNotNull(
            $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $alice, 'card' => $unique]),
            'The first opener must own the unique card.',
        );
        $this->assertNull(
            $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $bob, 'card' => $unique]),
            'A claimed 1/1 must never be drawn by another player.',
        );
    }

    public function testExtensionLeftWithOnlyClaimedUniquesIsNoLongerDrawable(): void
    {
        $extension = new Extension()
            ->setName('Claimed-out extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        // The ONLY published card is a one-of-one unique.
        $unique = new Card()
            ->setName('Sole one of one')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::RARE)
            ->setExtension($extension)
            ->setUnique(true)
        ;
        $unique->setImageName('default_card.png');
        $this->entityManager->persist($unique);

        $owner = new DiscordUser()->setDiscordId('uniq-owner-' . uniqid())->setUsername('Owner');
        $this->entityManager->persist($owner);
        $this->entityManager->flush();

        $cardRepository = $this->entityManager->getRepository(Card::class);
        \assert($cardRepository instanceof \App\Repository\CardRepository);

        // Before the claim, the hub/controller drawability check lists the extension…
        $this->assertContains((string) $extension->getId(), $cardRepository->findExtensionIdsWithPublishedCards());

        $cardRepository->claimUnique($unique, $owner);

        // …and once its only card is claimed, both the drawability check and the
        // actual draw pool agree the extension is exhausted (no more "Ouvrir"
        // button leading straight into a draw error).
        $this->assertNotContains((string) $extension->getId(), $cardRepository->findExtensionIdsWithPublishedCards());
        $this->assertSame([], $cardRepository->findDrawablePool($extension));
    }

    /**
     * @param list<array<string, int>>|null $rarityRates plain weight maps, wrapped per-slot with $holoChance
     *
     * @return array{user: DiscordUser, booster: Booster}
     */
    private function createScenario(
        int $boosterQuantity,
        int $holoChance = 10,
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

        $weightMaps = $rarityRates ?? [['common' => 100], ['common' => 100], ['common' => 60, 'rare' => 30, 'legendary' => 10]];
        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates(array_map(
                static fn (array $rarities): array => ['rarities' => $rarities, 'holoChance' => $holoChance],
                $weightMaps,
            ))
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
