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

/**
 * The player profile masking rule: a card of the profile is only shown in
 * clear when the visitor ALSO owns it — otherwise card back, and the name must
 * not leak anywhere in the DOM (no text, no alt, no title, no aria).
 */
final class PlayerProfileTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    private DiscordUser $rival;

    private Extension $extension;

    private Card $sharedCard;

    private Card $secretCard;

    private Card $uniqueCard;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);

        $this->createScenario();
    }

    public function testProfileHeaderShowsIdentityRankAndStats(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($this->rival->getUsername(), $crawler->filter('h1')->text());
        $this->assertStringContainsString('joueur depuis', $crawler->filter('main')->text());
        $this->assertStringContainsString('3 cartes distinctes', $crawler->filter('[data-testid="profile-completion"]')->text());

        $stats = $crawler->filter('[data-testid="profile-stats"]')->text();
        $this->assertStringContainsString('4 cartes au total', $stats);
        $this->assertStringContainsString('1 holo', $stats);
        $this->assertStringContainsString('1 unique 1/1', $stats);
        $this->assertStringContainsString('#', $crawler->filter('[data-testid="profile-rank"]')->text());
    }

    public function testSharedCardIsShownInClear(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->sharedCard->getName())));
        // the profile's own quantities are public: ×2 and one holo chip
        $this->assertStringContainsString('×2', $crawler->filter('[data-testid="profile-grid"]')->text());
    }

    public function testUnsharedCardsAreMaskedWithoutLeakingTheirNames(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        // secret + unique: two card backs
        $this->assertCount(2, $crawler->filter('[data-testid="masked-card"]'));

        // no leak at all in the HTML — covers text, alt, title and aria attributes
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($this->secretCard->getName(), $html);
        $this->assertStringNotContainsString($this->uniqueCard->getName(), $html);
    }

    public function testCardCountsStayVisibleOnMaskedUniverses(): void
    {
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString(
            "3 cartes dont 2 que tu n'as pas encore",
            $crawler->filter('[data-testid="universe-count"]')->text(),
        );
    }

    public function testOwnProfileShowsEverythingInClear(): void
    {
        // the rival visits their own profile: nothing is masked, the 1/1 included
        $this->authenticateClient($this->client, $this->rival->getDiscordId());
        $crawler = $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="masked-card"]'));
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->secretCard->getName())));
        $this->assertCount(1, $crawler->filter(\sprintf('img[alt="%s"]', $this->uniqueCard->getName())));
        $this->assertStringNotContainsString("que tu n'as pas encore", $crawler->filter('main')->text());
    }

    public function testAnotherPlayersUniqueIsAlwaysMaskedForVisitors(): void
    {
        // nobody but the claimer can own a 1/1, so it can never be shown to a visitor
        $this->client->request('GET', '/joueur/' . $this->rival->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            $this->uniqueCard->getName(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testEmptyProfileShowsAnEmptyState(): void
    {
        $empty = new DiscordUser()
            ->setDiscordId((string) random_int(300000000000000000, 999999999999999999))
            ->setUsername('Empty ' . uniqid())
        ;
        $this->entityManager->persist($empty);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/joueur/' . $empty->getDiscordId());

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="empty-state"]'));
    }

    public function testUnknownPlayerIsNotFound(): void
    {
        $this->client->request('GET', '/joueur/111111111111111111');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonNumericIdentifierIsNotFound(): void
    {
        $this->client->request('GET', '/joueur/not-a-discord-id');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A rival with 3 cards: one the visitor also owns (shared), one the
     * visitor doesn't (secret), and a claimed 1/1 (unique).
     */
    private function createScenario(): void
    {
        $this->extension = new Extension()
            ->setName('Univers profil ' . uniqid())
            ->setDescription('Univers du profil')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($this->extension);

        $this->rival = new DiscordUser()
            ->setDiscordId((string) random_int(300000000000000000, 999999999999999999))
            ->setUsername('Rival ' . uniqid())
        ;
        $this->entityManager->persist($this->rival);

        $this->sharedCard = $this->createCard('Carte Partagée ' . uniqid());
        $this->secretCard = $this->createCard('Carte Secrète ' . uniqid());
        $this->uniqueCard = $this->createCard('Unique Mystère ' . uniqid(), CardRarityEnum::LEGENDARY);
        $this->uniqueCard->setUnique(true);
        $this->uniqueCard->setClaimedBy($this->rival);

        $this->giveCard($this->rival, $this->sharedCard, quantity: 2, holoQuantity: 1);
        $this->giveCard($this->rival, $this->secretCard);
        $this->giveCard($this->rival, $this->uniqueCard);
        $this->giveCard($this->user, $this->sharedCard);

        $this->entityManager->flush();
    }

    private function createCard(string $name, CardRarityEnum $rarity = CardRarityEnum::COMMON): Card
    {
        $card = new Card()
            ->setName($name)
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
