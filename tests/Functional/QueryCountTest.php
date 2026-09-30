<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\BoosterOpeningCard;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Entity\MarketPurchase;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Twig\Components\BoosterHub;
use App\Twig\Components\MarketBoard;
use App\Twig\Components\MyShop;
use App\Twig\Components\RecycleHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

// Generous bounds on purpose: they catch an N+1 or a getter re-run per template call, not a query more or less
final class QueryCountTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private DiscordUser $me;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.app')->clear();
        $entityManager = $this->entityManager();
        $this->me = $this->authenticateClient($this->client, self::BARLITO);
        $other = static::getContainer()->get(DiscordUserRepository::class)->find(self::JUJU);
        \assert($other instanceof DiscordUser);
        $booster = $entityManager->getRepository(Booster::class)->findOneBy([]);
        \assert($booster instanceof Booster);

        $this->extension = new Extension()->setName('Query count ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $entityManager->persist($this->extension);

        $cards = [];
        for ($i = 0; $i < 8; ++$i) {
            $card = new Card()->setName('Query count card ' . uniqid())->setDescription('Test')->setExtension($this->extension)->setStatus(CardStatusEnum::PUBLISHED)->setRarity(CardRarityEnum::COMMON);
            $card->setImageName('default_card.png');
            $entityManager->persist($card);
            $entityManager->persist(new UserCard()->setDiscordUser($this->me)->setCard($card)->setQuantity(3)->setHoloQuantity(1));
            $entityManager->persist(new UserCard()->setDiscordUser($other)->setCard($card)->setQuantity(2)->setHoloQuantity(1));
            $cards[] = $card;
        }

        foreach ([0, 1, 2, 3, 4, 5] as $i) {
            $opening = new BoosterOpening($this->me, $booster, 4000 + $i, new \DateTimeImmutable('-' . $i . ' hours'));
            $entityManager->persist($opening);
            $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $cards[$i], 1, 0));
            $opening->addBoosterOpeningCard(new BoosterOpeningCard($opening, $cards[$i + 1], 1, 0));

            $listing = new MarketListing($i % 2 ? $this->me : $other, $cards[$i], false, 10 + $i);
            $entityManager->persist($listing);
            $entityManager->persist(new MarketPurchase($listing, $i % 2 ? $other : $this->me, $i % 2 ? $this->me : $other, 10 + $i, '50000000', new \DateTimeImmutable()));
        }

        $entityManager->flush();
        // the DBAL query stack only resets once a request went through: drop the seeding queries
        $this->client->request('GET', '/univers');
    }

    public function testTheBoosterHubPageStaysLean(): void
    {
        $this->assertLean('/boosters', 15);
    }

    public function testTheBoosterHubReadsItsSharedListsOnlyOncePerRender(): void
    {
        $queries = $this->queriesOf(fn () => $this->createLiveComponent(BoosterHub::class, client: $this->client)->render());

        $this->assertLessThanOrEqual(10, \count($queries));
        $this->assertSame(1, $this->countMatching($queries, 'COALESCE(b0_.name, e1_.name)'), 'findPublished() ran more than once');
        $this->assertSame(1, $this->countMatching($queries, 'SELECT DISTINCT c0_.extension_id'), 'the drawable pool was read more than once');
    }

    public function testEveryBoosterHubActionRendersWithoutRepeatingTheBoosterList(): void
    {
        $booster = $this->entityManager()->getRepository(Booster::class)->findOneBy([]);
        \assert($booster instanceof Booster);
        $hub = $this->createLiveComponent(BoosterHub::class, client: $this->client);
        $hub->render();

        $queries = $this->queriesOf(fn () => $hub->call('askPurchase', ['boosterId' => (string) $booster->getId()]));

        $this->assertSame(1, $this->countMatching($queries, 'COALESCE(b0_.name, e1_.name)'));
        $this->assertLessThanOrEqual(14, \count($queries));
    }

    public function testTheMarketBoardStaysLean(): void
    {
        $this->assertLean('/marche', 14);
        $queries = $this->queriesOf(fn () => $this->createLiveComponent(MarketBoard::class, client: $this->client)->render());
        $this->assertLessThanOrEqual(9, \count($queries));
    }

    public function testMyShopStaysLean(): void
    {
        $this->assertLean('/marche/ma-boutique', 15);
        $queries = $this->queriesOf(fn () => $this->createLiveComponent(MyShop::class, client: $this->client)->render());
        $this->assertLessThanOrEqual(10, \count($queries));
    }

    public function testTheCollectionStaysLean(): void
    {
        $this->assertLean('/collection', 18);
        $this->assertLean('/collection/' . $this->extension->getSlug(), 18);
    }

    public function testTheTradePagesStayLean(): void
    {
        $this->assertLean('/echanges', 12);
        $this->assertLean('/echanges/nouveau', 9);
        $this->assertLean('/echanges/nouveau/' . self::JUJU, 16);
    }

    public function testTheOpeningHistoryAndRecyclingStayLean(): void
    {
        $this->assertLean('/mes-ouvertures', 18);
        $this->assertLean('/recyclage', 15);
        $queries = $this->queriesOf(fn () => $this->createLiveComponent(RecycleHub::class, client: $this->client)->render());
        $this->assertLessThanOrEqual(12, \count($queries));
    }

    public function testTheAdminListsDoNotQueryPerRow(): void
    {
        foreach (['/admin/booster', '/admin/discord-user', '/admin/market-listing', '/admin/market-purchase', '/admin/booster-opening', '/admin/trade-offer'] as $url) {
            $this->assertLean($url, 10);
        }
    }

    private function assertLean(string $url, int $maxQueries): void
    {
        $queries = $this->queriesOf(fn () => $this->client->request('GET', $url));

        $this->assertResponseIsSuccessful($url);
        $this->assertLessThanOrEqual($maxQueries, \count($queries), \sprintf('%s ran %d queries', $url, \count($queries)));
    }

    /**
     * @return list<string> SQL of every query the request ran
     */
    private function queriesOf(callable $request): array
    {
        $this->client->enableProfiler();
        $request();
        $profile = $this->client->getProfile();
        $this->assertNotFalse($profile);

        return array_map(
            static fn (array $query): string => (string) $query['sql'],
            $profile->getCollector('db')->getQueries()['default'] ?? [],
        );
    }

    /**
     * @param list<string> $queries
     */
    private function countMatching(array $queries, string $needle): int
    {
        return \count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, $needle)));
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
