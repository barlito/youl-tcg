<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LeaderboardControllerTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->extension = new Extension()
            ->setName('Univers classement ' . uniqid())
            ->setDescription('Univers du classement')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);
    }

    public function testRanksCollectorsByCompletionWithTheirMetrics(): void
    {
        $rival = $this->createPlayer('Rival');
        $cards = [$this->createCard(), $this->createCard(), $this->createCard()];

        // rival: 2 distinct cards (3 copies, 1 holo) + 1 opened pack; me: 1 distinct card
        $this->giveCard($rival, $cards[0], quantity: 2, holoQuantity: 1);
        $this->giveCard($rival, $cards[1]);
        $this->giveCard($this->user, $cards[0]);

        $booster = new Booster()
            ->setExtension($this->extension)
            ->setName('Pack classement')
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($booster);
        $this->entityManager->persist(new BoosterOpening($rival, $booster, 42, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/classement');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="leaderboard-row"]');
        $rivalPosition = $this->positionOf($rows, $rival->getUsername());
        $this->assertLessThan($this->positionOf($rows, $this->user->getUsername()), $rivalPosition);

        $rivalRow = $rows->eq($rivalPosition);
        $this->assertStringContainsString('2 distinctes', $rivalRow->text());
        $this->assertStringContainsString('✦1', $rivalRow->text());
        // ▣N is the mobile summary of the opened packs count
        $this->assertStringContainsString('▣1', $rivalRow->text());
        $this->assertSame('/joueur/' . $rival->getDiscordId(), $rivalRow->attr('href'));
    }

    public function testTiesBreakOnTotalCopiesThenUsername(): void
    {
        $hoarder = $this->createPlayer('Aaa Hoarder');
        $minimalist = $this->createPlayer('Aaa Minimalist');
        $card = $this->createCard();

        // same distinct count: more copies wins
        $this->giveCard($hoarder, $card, quantity: 5);
        $this->giveCard($minimalist, $card);
        // both empty-handed: username (asc, case-insensitive) decides
        $late = $this->createPlayer('Zzz Empty');
        $early = $this->createPlayer('Aaa Empty');
        $this->entityManager->flush();

        $rows = $this->client->request('GET', '/classement')->filter('[data-testid="leaderboard-row"]');

        $this->assertLessThan($this->positionOf($rows, $minimalist->getUsername()), $this->positionOf($rows, $hoarder->getUsername()));
        $this->assertLessThan($this->positionOf($rows, $late->getUsername()), $this->positionOf($rows, $early->getUsername()));
    }

    public function testCurrentUserRowIsHighlighted(): void
    {
        $crawler = $this->client->request('GET', '/classement');

        self::assertResponseIsSuccessful();
        $highlighted = $crawler->filter('[data-testid="leaderboard-row"][data-current]');
        $this->assertCount(1, $highlighted);
        $this->assertStringContainsString($this->user->getUsername(), $highlighted->text());
    }

    public function testUniquesAreCountedWithoutRevealingWhichOnes(): void
    {
        $rival = $this->createPlayer('Détenteur');
        $unique = $this->createCard(CardRarityEnum::LEGENDARY);
        $unique->setName('Unique Mystère ' . uniqid());
        $unique->setUnique(true);
        $unique->setClaimedBy($rival);
        $this->giveCard($rival, $unique);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/classement');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="leaderboard-row"]');
        $rivalRow = $rows->eq($this->positionOf($rows, $rival->getUsername()));
        $this->assertStringContainsString('◆1', $rivalRow->text());
        // the count shows, the card itself stays a mystery
        $this->assertStringNotContainsString($unique->getName(), (string) $this->client->getResponse()->getContent());
    }

    public function testHeaderNavigationLinksToTheLeaderboard(): void
    {
        $crawler = $this->client->request('GET', '/classement');

        self::assertResponseIsSuccessful();
        $navLink = $crawler->filter('header nav a[href="/classement"]');
        $this->assertCount(1, $navLink);
        $this->assertSame('Classement', trim($navLink->text()));
    }

    private function positionOf(Crawler $rows, string $username): int
    {
        foreach ($rows as $index => $row) {
            if (str_contains($row->textContent, $username)) {
                return $index;
            }
        }

        $this->fail(\sprintf('No leaderboard row for "%s".', $username));
    }

    private function createPlayer(string $username): DiscordUser
    {
        $player = new DiscordUser()
            ->setDiscordId((string) random_int(300000000000000000, 999999999999999999))
            ->setUsername($username . ' ' . uniqid())
        ;
        $this->entityManager->persist($player);

        return $player;
    }

    private function createCard(CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName('Card L ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity($rarity)
            ->setExtension($this->extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function giveCard(DiscordUser $owner, Card $card, int $quantity = 1, int $holoQuantity = 0): void
    {
        $this->entityManager->persist(
            new UserCard()
                ->setDiscordUser($owner)
                ->setCard($card)
                ->setQuantity($quantity)
                ->setHoloQuantity($holoQuantity),
        );
    }
}
