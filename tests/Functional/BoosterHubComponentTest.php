<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\BoosterRepository;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterHubComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju has no UserBooster fixture, unlike Barlito who starts with a stocked inventory.
    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testClaimBoosterCreditsTheInventoryAndDecrementsTheQuota(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);

        $userBooster = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $booster])
        ;

        $this->assertNotNull($userBooster);
        $this->assertSame(1, $userBooster->getQuantity());
        $this->assertNull($component->component()->error);
    }

    public function testOpeningAClaimedBoosterFillsTheCollectionAndTheSummary(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $component->call('openBooster', ['boosterId' => (string) $booster->getId()]);

        $hub = $component->component();
        $this->assertNull($hub->error);
        $this->assertNotNull($hub->opening);

        $rendered = (string) $component->render();
        $this->assertStringContainsString('Ton butin', $rendered);
        // Every drawn card was new for this empty-inventory user.
        $this->assertStringContainsString('NOUVEAU', $rendered);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $booster]);
        $this->assertNotNull($userBooster);
        $this->assertSame(0, $userBooster->getQuantity());

        $userCards = $entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $user]);
        $totalCards = array_sum(array_map(static fn (UserCard $userCard): int => $userCard->getQuantity(), $userCards));
        $this->assertSame($booster->getCardCount(), $totalCards);
    }

    public function testClaimBoosterWithMalformedIdReportsNotFoundInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => 'not-a-uuid']);

        $this->assertSame('Booster introuvable.', $component->component()->error);
    }

    public function testErrorsAreReportedInFrench(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('openBooster', ['boosterId' => (string) $booster->getId()]);

        $this->assertSame('Tu ne possèdes pas ce booster.', $component->component()->error);
    }

    public function testRevealRendersCardsOrderedRarestLast(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $component->call('openBooster', ['boosterId' => (string) $booster->getId()]);

        $crawler = new Crawler((string) $component->render());
        $rarities = $crawler->filter('.opening__reveal-card')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-rarity'),
        );

        $this->assertCount($booster->getCardCount(), $rarities);

        $rank = array_flip(array_map(
            static fn (CardRarityEnum $rarity): string => $rarity->value,
            CardRarityEnum::ascending(),
        ));
        $ranks = array_map(static fn (string $rarity): int => $rank[$rarity], $rarities);
        $sortedRanks = $ranks;
        sort($sortedRanks);

        $this->assertSame($sortedRanks, $ranks, 'Reveal cards must be ordered from common to rarest (climax last).');
    }

    public function testOpeningWithoutInventoryReportsAnError(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('openBooster', ['boosterId' => (string) $booster->getId()]);

        $hub = $component->component();
        $this->assertNull($hub->opening);
        $this->assertNotNull($hub->error);
    }

    private function firstPublishedBooster(): \App\Entity\Booster
    {
        // The Cyberpunk extension is the one with published cards in the fixtures.
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                return $booster;
            }
        }

        $this->fail('Fixture booster for the Cyberpunk extension not found, load the alice fixtures first.');
    }
}
