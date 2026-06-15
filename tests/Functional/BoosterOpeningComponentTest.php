<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\DiscordUser;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Repository\BoosterRepository;
use App\Service\Booster\BoosterClaimService;
use App\Twig\Components\BoosterOpening;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterOpeningComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    public function testOpenDrawsCardsDebitsInventoryAndFlagsNewCards(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $opening = $component->component();
        $this->assertNull($opening->error);
        $this->assertNotNull($opening->opening);

        $rendered = (string) $component->render();
        // Empty-inventory user → every drawn card is new.
        $this->assertStringContainsString('NOUVEAU', $rendered);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $userBooster = $entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user, 'booster' => $booster]);
        $this->assertNotNull($userBooster);
        $this->assertSame(0, $userBooster->getQuantity());

        $userCards = $entityManager->getRepository(UserCard::class)->findBy(['discordUser' => $user]);
        $totalCards = array_sum(array_map(static fn (UserCard $userCard): int => $userCard->getQuantity(), $userCards));
        $this->assertSame($booster->getCardCount(), $totalCards);
    }

    public function testRevealRendersCardsOrderedRarestLast(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $rendered = (string) $component->render();
        // 3D pack is wired into the page.
        $this->assertStringContainsString('pack-opening-3d', $rendered);
        $this->assertStringContainsString('/models/pack-wide.gltf', $rendered);

        $crawler = new Crawler($rendered);
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

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );
        $component->call('open');

        $opening = $component->component();
        $this->assertNull($opening->opening);
        $this->assertNotNull($opening->error);
    }

    public function testResetAllowsOpeningAnotherPack(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        $booster = $this->firstPublishedBooster();
        $this->claim($user, $booster);
        $this->claim($user, $booster);

        $component = $this->createLiveComponent(
            BoosterOpening::class,
            data: ['boosterId' => (string) $booster->getId()],
            client: $client,
        );

        $component->call('open');
        $this->assertNotNull($component->component()->opening, 'first open');

        $component->call('reset');
        $this->assertNull($component->component()->opening, 'reset clears the opening');

        $component->call('open');
        $secondOpening = $component->component();
        $this->assertNull($secondOpening->error, 'second open must not error');
        $this->assertNotNull($secondOpening->opening, 'second open');
    }

    private function claim(DiscordUser $user, Booster $booster): void
    {
        static::getContainer()->get(BoosterClaimService::class)->claim($user, $booster);
    }

    private function firstPublishedBooster(): Booster
    {
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                return $booster;
            }
        }

        $this->fail('Fixture booster for the Cyberpunk extension not found, load the alice fixtures first.');
    }
}
