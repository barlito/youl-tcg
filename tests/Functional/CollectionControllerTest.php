<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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

final class CollectionControllerTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private Extension $extensionA;

    private Extension $extensionB;

    /** @var list<Card> */
    private array $cardsA = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->createScenario();
    }

    public function testBannerShowsUserAndRealQuota(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->user->getUsername(), $crawler->filter('h1')->text());

        $quota = $crawler->filter('[data-testid="daily-packs"]')->text();
        $this->assertStringContainsString('2', $quota);
        $this->assertStringContainsString('/ 2', $quota);

        $this->assertStringContainsString(
            'Cartes possédées · 5',
            $crawler->filter('main')->text(),
        );
    }

    public function testCompletionStripShowsPerUniverseNumbers(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $strip = $crawler->filter('[data-testid="completion-strip"]')->text();
        // Extension A: 2 owned out of 4 published (the draft card must not count).
        $this->assertStringContainsString('50%', $strip);
        $this->assertStringContainsString('2/4 cartes', $strip);
        // Extension B: nothing owned.
        $this->assertStringContainsString('0/2 cartes', $strip);
    }

    public function testFilterTabsShowOwnedCounts(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $filters = $crawler->filter('[data-testid="filters"]')->text();
        $this->assertStringContainsString(\sprintf('%s · 2', $this->extensionA->getName()), $filters);
        $this->assertStringContainsString(\sprintf('%s · 0', $this->extensionB->getName()), $filters);
    }

    public function testGridShowsOwnedCardsWithQuantityBadges(): void
    {
        $crawler = $this->client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="collection-grid"]');
        $this->assertStringContainsString('×3', $grid->text());
        $this->assertStringContainsString('✦1', $grid->text());
        // The zero-quantity inventory row must not produce a card.
        $this->assertCount(0, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[2]->getName())));
    }

    public function testExtensionFilterOnlyShowsItsCards(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionA->getSlug());

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('[data-testid="collection-grid"]');
        $this->assertCount(1, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[0]->getName())));
        $this->assertCount(1, $grid->filter(\sprintf('img[alt="%s"]', $this->cardsA[1]->getName())));

        // Active tab is highlighted.
        $this->assertStringContainsString(
            $this->extensionA->getName(),
            $crawler->filter('[data-testid="filters"] a.bg-primary')->text(),
        );
    }

    public function testEmptyStateWhenNoCardOwnedInExtension(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extensionB->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="collection-grid"]'));
        $this->assertStringContainsString('Vide.', $crawler->filter('[data-testid="empty-state"]')->text());
    }

    public function testUnknownExtensionIsNotFound(): void
    {
        // a well-formed but non-existent slug → controller 404
        $this->client->request('GET', '/collection/this-extension-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedSlugIsNotFound(): void
    {
        // chars outside the [a-z0-9-] route requirement → no route matches → 404
        $this->client->request('GET', '/collection/Invalid_Slug');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Extension A: 4 published cards (+1 draft) — the user owns 2 of them
     * (one ×3 with 1 holo, one ×1) plus a zero-quantity leftover row.
     * Extension B: 2 published cards — the user owns none.
     */
    private function createScenario(): void
    {
        $this->extensionA = $this->createExtension('Collection ext A ' . uniqid());
        $this->extensionB = $this->createExtension('Collection ext B ' . uniqid());

        for ($i = 0; $i < 4; ++$i) {
            $this->cardsA[] = $this->createCard($this->extensionA, \sprintf('Card A%d %s', $i, uniqid()));
        }
        $this->createCard($this->extensionA, 'Draft card ' . uniqid(), CardStatusEnum::DRAFT);

        for ($i = 0; $i < 2; ++$i) {
            $this->createCard($this->extensionB, \sprintf('Card B%d %s', $i, uniqid()));
        }

        $this->createUserCard($this->cardsA[0], quantity: 3, holoQuantity: 1);
        $this->createUserCard($this->cardsA[1], quantity: 1);
        $this->createUserCard($this->cardsA[2], quantity: 0);

        $this->entityManager->flush();
    }

    private function createExtension(string $name): Extension
    {
        $extension = new Extension()
            ->setName($name)
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);

        return $extension;
    }

    private function createCard(Extension $extension, string $name, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $card = new Card()
            ->setName($name)
            ->setDescription('Test card')
            ->setStatus($status)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        return $card;
    }

    private function createUserCard(Card $card, int $quantity, int $holoQuantity = 0): void
    {
        $userCard = new UserCard()
            ->setDiscordUser($this->user)
            ->setCard($card)
            ->setQuantity($quantity)
            ->setHoloQuantity($holoQuantity)
        ;
        $this->entityManager->persist($userCard);
    }
}
