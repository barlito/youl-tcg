<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Repository\BoosterRepository;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterHubComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    public function testClaimBoosterCreditsTheInventoryAndDecrementsTheQuota(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client);
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

    public function testOpeningAClaimedBoosterFillsTheCollectionAndTheModal(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client);
        $booster = $this->firstPublishedBooster();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->call('claimBooster', ['boosterId' => (string) $booster->getId()]);
        $component->call('openBooster', ['boosterId' => (string) $booster->getId()]);

        $hub = $component->component();
        $this->assertNull($hub->error);
        $this->assertNotNull($hub->opening);
        $this->assertStringContainsString('Félicitations', (string) $component->render());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $booster]);
        $this->assertNotNull($userBooster);
        $this->assertSame(0, $userBooster->getQuantity());

        $userCards = $entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $user]);
        $totalCards = array_sum(array_map(static fn (UserCard $userCard): int => $userCard->getQuantity(), $userCards));
        $this->assertSame($booster->getCardCount(), $totalCards);
    }

    public function testOpeningWithoutInventoryReportsAnError(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client);
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
