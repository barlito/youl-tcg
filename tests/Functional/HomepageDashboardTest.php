<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\UserCard;
use App\Entity\WishlistEntry;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Repository\CardRepository;
use App\Repository\DiscordUserRepository;
use App\Tests\DrawnCardTrait;
use App\Tests\FeatureFlagTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomepageDashboardTest extends WebTestCase
{
    use DrawnCardTrait;
    use FeatureFlagTrait;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $me;

    private DiscordUser $other;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.app')->clear();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->me = $this->authenticateClient($this->client, self::JUJU);
        $this->other = static::getContainer()->get(DiscordUserRepository::class)->find(self::BARLITO);
        $this->extension = $this->extension('Home extension');
    }

    // ------------------------------------------------------------ « Ton QG »

    public function testTheQgShowsTheDailyClaimsTheUnopenedPacksAndTheRank(): void
    {
        $crawler = $this->home();

        $this->assertStringContainsString($this->me->getUsername(), $crawler->filter('[data-testid="home-today"] h2')->text());
        $claims = $crawler->filter('[data-testid="today-claims"]')->text();
        $this->assertStringContainsString('2/2', $claims);
        $this->assertStringContainsString('à réclamer', $claims);
        $this->assertStringContainsString('au classement', $crawler->filter('[data-testid="today-collection"]')->text());
        $this->assertCount(1, $crawler->filter('[data-testid="today-streak"]'));
    }

    public function testTheFusionTileCountsOnlyTheFreeNormalCopies(): void
    {
        $card = $this->card('Fusable');
        $this->own($this->me, $card, 20);

        $this->assertStringContainsString('✦ 2', $this->home()->filter('[data-testid="today-fusion"]')->text());

        // a listed copy is engaged: 19 free normals left, one fusion
        $this->entityManager->persist(new MarketListing($this->me, $card, false, 10));
        $this->entityManager->flush();
        static::getContainer()->get('cache.app')->clear();

        $fusion = $this->home()->filter('[data-testid="today-fusion"]')->text();
        $this->assertStringContainsString('✦ 1', $fusion);
        $this->assertStringContainsString('sur 1 carte', $fusion);
    }

    public function testTheWishlistTileCountsTheWishedCardsOtherPlayersSell(): void
    {
        $wished = $this->card('Wished');
        $mine = $this->card('Wished and mine');
        $this->own($this->me, $wished, 1);
        $this->own($this->me, $mine, 1);
        $this->own($this->other, $wished, 1);
        $this->entityManager->persist(new WishlistEntry($this->me, $wished));
        $this->entityManager->persist(new WishlistEntry($this->me, $mine));
        $this->entityManager->persist(new MarketListing($this->other, $wished, false, 10));
        $this->entityManager->persist(new MarketListing($this->me, $mine, false, 10));
        $this->entityManager->flush();

        $tile = $this->home()->filter('[data-testid="today-wishlist"]');

        $this->assertStringContainsString('♡ 1', $tile->text());
        $this->assertStringContainsString('wishlist=1', (string) $tile->attr('href'));
    }

    public function testSwitchedOffFeaturesHideTheirTiles(): void
    {
        foreach ([FeatureEnum::TRADES, FeatureEnum::FUSION, FeatureEnum::RECYCLING, FeatureEnum::WISHLIST] as $feature) {
            $this->setFeature($feature, false);
        }

        $crawler = $this->home();

        foreach (['today-trades', 'today-fusion', 'today-recycle', 'today-wishlist'] as $tile) {
            $this->assertCount(0, $crawler->filter('[data-testid="' . $tile . '"]'), $tile);
        }
        $this->assertCount(1, $crawler->filter('[data-testid="today-claims"]'));
    }

    // ---------------------------------------------------- presque complets

    public function testTheUniversesToFinishListTheStartedOnesWithTheRewardProgress(): void
    {
        $this->extension->setCompletionRewardCoins(300);
        $cards = [];
        for ($i = 0; $i < 10; ++$i) {
            $cards[] = $this->card('Set card ' . $i);
        }
        foreach (\array_slice($cards, 0, 9) as $card) {
            $this->own($this->me, $card, 1);
        }
        $this->recordDraw($this->entityManager, $this->me, $cards[0]);
        $complete = $this->extension('Complete extension');
        $this->own($this->me, $this->card('Only card', $complete), 1);

        $section = $this->home()->filter('[data-testid="home-to-finish"]');

        $tile = $section->filter('[data-testid="to-finish-universe"]')->reduce(fn (Crawler $node): bool => str_contains($node->text(), $this->extension->getName()));
        $this->assertCount(1, $tile);
        $this->assertStringContainsString('90 %', $tile->text());
        $this->assertStringContainsString('il en manque 1', $tile->text());
        $this->assertStringContainsString('★ 1/10 tirées par toi · +300 YLC', $tile->filter('[data-testid="to-finish-reward"]')->text());
        $this->assertStringNotContainsString($complete->getName(), $section->text(), 'A finished universe has nothing left to chase.');
    }

    // ------------------------------------------------------------ en direct

    public function testTheLiveFeedNamesThePlayerAndTheUniverseButNeverTheCard(): void
    {
        $legendary = $this->card('Secret legendary', rarity: CardRarityEnum::LEGENDARY);
        $unique = $this->card('Secret unique', rarity: CardRarityEnum::LEGENDARY);
        $unique->setUnique(true);
        $this->recordDraw($this->entityManager, $this->other, $legendary);
        $this->recordDraw($this->entityManager, $this->other, $unique);
        $this->recordDraw($this->entityManager, $this->other, $this->card('Plain common'));

        $live = $this->home()->filter('[data-testid="home-live"]');
        $pulls = $live->filter('[data-testid="live-pull"]')->reduce(fn (Crawler $node): bool => str_contains($node->text(), $this->extension->getName()));

        $this->assertCount(2, $pulls, 'Only the legendary and the 1/1 make the feed.');
        $this->assertStringContainsString($this->other->getUsername(), $pulls->text());
        $this->assertStringContainsString('a trouvé une 1/1', $live->text());
        $this->assertStringContainsString('a tiré une légendaire', $live->text());
        $this->assertStringNotContainsString($legendary->getName(), $live->html());
        $this->assertStringNotContainsString($unique->getName(), $live->html());
        $this->assertStringNotContainsString((string) $legendary->getId(), $live->html());
    }

    public function testTheCountersShowTheUniqueHunt(): void
    {
        $uniques = static::getContainer()->get(CardRepository::class)->countPublishedUniques();

        $counters = $this->home()->filter('[data-testid="live-counters"]')->text();

        $this->assertStringContainsString('1/1 trouvées', $counters);
        $this->assertStringContainsString($uniques['found'] . ' / ' . $uniques['total'], $counters);
    }

    public function testTheLatestListingsShowOtherPlayersCardsOnly(): void
    {
        $mine = $this->card('My listed card');
        $theirs = $this->card('Their listed card');
        $this->own($this->me, $mine, 1);
        $this->own($this->other, $theirs, 1);
        $this->entityManager->persist(new MarketListing($this->me, $mine, false, 10));
        $this->entityManager->persist(new MarketListing($this->other, $theirs, true, 42));
        $this->entityManager->flush();

        $listings = $this->home()->filter('[data-testid="live-listings"]')->text();

        $this->assertStringContainsString($theirs->getName(), $listings);
        $this->assertStringContainsString('42', $listings);
        $this->assertStringNotContainsString($mine->getName(), $listings);
    }

    private function home(): Crawler
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function extension(string $name): Extension
    {
        $extension = new Extension()->setName($name . ' ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($extension);
        $this->entityManager->flush();

        return $extension;
    }

    private function card(string $name, ?Extension $extension = null, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName($name . ' ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension ?? $this->extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function own(DiscordUser $user, Card $card, int $quantity): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity));
        $this->entityManager->flush();
    }
}
