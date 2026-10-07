<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Exception\Feature\FeatureDisabledException;
use App\Repository\DiscordUserRepository;
use App\Service\Duel\DeckService;
use App\Tests\DuelTestTrait;
use App\Tests\FeatureFlagTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DuelApiTest extends WebTestCase
{
    use DuelTestTrait;
    use FeatureFlagTrait;
    use JwtAuthTrait;

    private const string JUJU = '195659530363731968';

    private const string BENJ = '232457563910832129';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $me;

    private Extension $extension;

    /**
     * @var list<Card>
     */
    private array $cards;

    private Card $terrain;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->me = $this->authenticateClient($this->client, self::JUJU);
        $this->extension = $this->duelExtension();
        $this->cards = $this->ownedCards($this->me, $this->extension);
        $this->terrain = $this->duelCard($this->extension, 'Niveau 24', terrain: true);
        $this->give($this->me, $this->terrain);
        $this->entityManager->flush();
    }

    // ------------------------------------------------------------ collection

    public function testTheCollectionListsPublishedOwnedCardsWithHoloCopiesInTheQuantity(): void
    {
        $tagged = $this->duelCard($this->extension, 'Benj holo', tags: ['character:benj', 'trait:machine']);
        $this->give($this->me, $tagged, quantity: 3, holoQuantity: 2);
        $draft = $this->duelCard($this->extension, 'Draft card', status: CardStatusEnum::DRAFT);
        $this->give($this->me, $draft);
        $hiddenUniverse = $this->duelExtension(ExtensionStatusEnum::DRAFT);
        $this->give($this->me, $this->duelCard($hiddenUniverse, 'Card of a draft universe'));
        $this->give($this->me, $this->duelCard($this->extension, 'Zero copy'), quantity: 0);
        $this->entityManager->flush();

        $body = $this->getJson('/api/duel/collection');

        self::assertResponseIsSuccessful();
        $byId = array_column($body['cards'], null, 'id');
        $this->assertCount(14, $byId, '12 cards + the terrain + the tagged one, nothing unpublished nor at zero.');
        $this->assertSame([
            'id' => (string) $tagged->getId(),
            'name' => 'Benj holo',
            'extension' => $this->extension->getSlug(),
            'rarity' => 'common',
            'unique' => false,
            'terrain' => false,
            'tags' => ['character:benj', 'trait:machine'],
            'quantity' => 3,
            'holoQuantity' => 2,
        ], $byId[(string) $tagged->getId()]);
        $this->assertTrue($byId[(string) $this->terrain->getId()]['terrain']);
        $this->assertArrayNotHasKey((string) $draft->getId(), $byId);
    }

    public function testTheCollectionOnlyShowsTheCallersCards(): void
    {
        $benj = $this->player(self::BENJ);
        $this->give($benj, $mine = $this->duelCard($this->extension, 'Benj only'));
        $this->entityManager->flush();

        $ids = array_column($this->getJson('/api/duel/collection')['cards'], 'id');

        $this->assertNotContains((string) $mine->getId(), $ids);
    }

    // ------------------------------------------------------------ decks

    public function testCreateListUpdateAndDeleteADeck(): void
    {
        $spare = $this->duelCard($this->extension, 'Spare');
        $this->give($this->me, $spare);
        $this->entityManager->flush();

        $created = $this->sendJson('POST', '/api/duel/decks', ['name' => 'Linettes', 'cards' => $this->ids($this->cards), 'terrain' => (string) $this->terrain->getId()]);

        self::assertResponseStatusCodeSame(201);
        $this->assertSame('Linettes', $created['name']);
        $expected = $this->ids($this->cards);
        sort($expected);
        $this->assertSame($expected, $created['cards']);
        $this->assertSame((string) $this->terrain->getId(), $created['terrain']);
        $this->assertTrue($created['valid']);
        $this->assertSame([], $created['missingCards']);
        $this->assertSame([], $created['issues']);

        $list = $this->getJson('/api/duel/decks');
        $this->assertSame(DeckService::MAX_DECKS, $list['maxDecks']);
        $this->assertSame(12, $list['deckSize']);
        $this->assertSame([$created['id']], array_column($list['decks'], 'id'));

        $cards = $this->ids($this->cards);
        $cards[0] = (string) $spare->getId();
        $updated = $this->sendJson('PUT', '/api/duel/decks/' . $created['id'], ['name' => 'Sans terrain', 'cards' => $cards]);

        self::assertResponseIsSuccessful();
        $this->assertSame('Sans terrain', $updated['name']);
        $this->assertNull($updated['terrain']);
        $this->assertContains((string) $spare->getId(), $updated['cards']);
        $this->assertNotContains((string) $this->cards[0]->getId(), $updated['cards']);

        $this->client->request('DELETE', '/api/duel/decks/' . $created['id']);
        self::assertResponseStatusCodeSame(204);
        $this->assertSame([], $this->getJson('/api/duel/decks')['decks']);
    }

    public function testAnInvalidDeckAnswers422WithViolationsPerField(): void
    {
        $cards = $this->ids($this->cards);
        $cards[11] = (string) $this->terrain->getId();

        $body = $this->sendJson('POST', '/api/duel/decks', ['name' => '', 'cards' => $cards, 'terrain' => (string) $this->cards[0]->getId()]);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('Deck invalide.', $body['error']);
        $this->assertSame(['name', 'cards[11]', 'terrain'], array_keys($body['violations']));
        $this->assertSame(0, $this->entityManager->getRepository(Deck::class)->count(['owner' => $this->me]));
    }

    public function testCardsOfSomeoneElseOrUnpublishedAreRefusedAsNotInTheCollection(): void
    {
        $benj = $this->player(self::BENJ);
        $this->give($benj, $foreign = $this->duelCard($this->extension, 'Benj card'));
        $draft = $this->duelCard($this->extension, 'Draft', status: CardStatusEnum::DRAFT);
        $this->give($this->me, $draft);
        $this->entityManager->flush();
        $cards = $this->ids($this->cards);
        $cards[0] = (string) $foreign->getId();
        $cards[1] = (string) $draft->getId();

        $body = $this->sendJson('POST', '/api/duel/decks', ['name' => 'Volé', 'cards' => $cards]);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame(['Carte introuvable dans ta collection.'], $body['violations']['cards[0]']);
        $this->assertSame(['Carte introuvable dans ta collection.'], $body['violations']['cards[1]']);
    }

    public function testADeckBecomesIncompleteWhenACardLeavesTheCollection(): void
    {
        $created = $this->sendJson('POST', '/api/duel/decks', ['name' => 'Deck', 'cards' => $this->ids($this->cards)]);
        $row = $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $this->me, 'card' => $this->cards[4]]);
        $this->assertInstanceOf(UserCard::class, $row);
        $row->setQuantity(0);
        $this->entityManager->flush();

        $deck = $this->getJson('/api/duel/decks')['decks'][0];

        $this->assertSame($created['id'], $deck['id']);
        $this->assertFalse($deck['valid']);
        $this->assertSame([(string) $this->cards[4]->getId()], $deck['missingCards']);
        $this->assertSame(['1 carte du deck n\'est plus dans ta collection.'], $deck['issues']);
    }

    public function testTheDeckCapIsEnforced(): void
    {
        for ($i = 0; $i < DeckService::MAX_DECKS; ++$i) {
            $this->entityManager->persist(new Deck($this->me, 'Deck ' . $i, $this->cards));
        }
        $this->entityManager->flush();

        $body = $this->sendJson('POST', '/api/duel/decks', ['name' => 'Un de trop', 'cards' => $this->ids($this->cards)]);

        self::assertResponseStatusCodeSame(409);
        $this->assertSame(\sprintf('Tu as déjà %d decks : supprimes-en un avant d\'en créer un nouveau.', DeckService::MAX_DECKS), $body['error']);
    }

    public function testSomeoneElsesDeckAnswersLikeAnUnknownOne(): void
    {
        $benj = $this->player(self::BENJ);
        $deck = new Deck($benj, 'Deck de Benj', $this->cards);
        $this->entityManager->persist($deck);
        $this->entityManager->flush();
        $payload = ['name' => 'Piraté', 'cards' => $this->ids($this->cards)];

        foreach ([(string) $deck->getId(), (string) Uuid::v7(), 'not-a-uuid'] as $id) {
            $body = $this->sendJson('PUT', '/api/duel/decks/' . $id, $payload);
            self::assertResponseStatusCodeSame(404);
            $this->assertSame(['error' => 'Deck introuvable.'], $body);

            $this->client->request('DELETE', '/api/duel/decks/' . $id);
            self::assertResponseStatusCodeSame(404);
        }

        $this->entityManager->clear();
        $this->assertSame('Deck de Benj', $this->entityManager->find(Deck::class, $deck->getId())?->getName());
        $this->assertSame([], $this->getJson('/api/duel/decks')['decks']);
    }

    public function testWritesRequireAJsonObject(): void
    {
        $this->client->request('POST', '/api/duel/decks', ['name' => 'form']);
        self::assertResponseStatusCodeSame(415);

        $this->client->request('POST', '/api/duel/decks', server: ['CONTENT_TYPE' => 'application/json'], content: '{nope');
        self::assertResponseStatusCodeSame(400);
        $this->assertSame(['error' => 'Corps JSON invalide : objet attendu.'], $this->json());

        $this->client->request('POST', '/api/duel/decks', server: ['CONTENT_TYPE' => 'application/json'], content: '["a"]');
        self::assertResponseStatusCodeSame(400);
    }

    // ------------------------------------------------------------ access

    public function testAnonymousCallsGetAJson401InsteadOfARedirect(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/duel/decks');

        self::assertResponseStatusCodeSame(401);
        $body = $this->json();
        $this->assertSame('Session expirée : reconnecte-toi sur Youl TCG.', $body['error']);
        $this->assertStringStartsWith('https://yc.youlz.fr/refresh_token?_target_path=', $body['loginUrl']);
    }

    public function testEveryEndpointIs404WhileTheFeatureIsOff(): void
    {
        $deck = new Deck($this->me, 'Deck', $this->cards);
        $this->entityManager->persist($deck);
        $this->entityManager->flush();
        $this->setFeature(FeatureEnum::DUEL, false);

        foreach ([['GET', '/api/duel/collection'], ['GET', '/api/duel/decks'], ['POST', '/api/duel/decks'], ['PUT', '/api/duel/decks/' . $deck->getId()], ['DELETE', '/api/duel/decks/' . $deck->getId()]] as [$method, $url]) {
            $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(404, $method . ' ' . $url);
            $this->assertSame(['error' => 'Introuvable.'], $this->json());
        }

        $this->assertSame(1, $this->entityManager->getRepository(Deck::class)->count(['owner' => $this->me]));
    }

    public function testTheServiceGuardsItsOwnEntryPointsWhileTheFeatureIsOff(): void
    {
        $this->setFeature(FeatureEnum::DUEL, false);

        $this->expectException(FeatureDisabledException::class);
        static::getContainer()->get(DeckService::class)->create($this->me, ['name' => 'Deck', 'cards' => $this->ids($this->cards)]);
    }

    public function testAnUnknownMethodAnswersJson(): void
    {
        $this->client->request('PATCH', '/api/duel/decks');

        self::assertResponseStatusCodeSame(405);
        $this->assertSame(['error' => 'Méthode non autorisée.'], $this->json());
    }

    private function player(string $discordId): DiscordUser
    {
        $player = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        $this->assertInstanceOf(DiscordUser::class, $player);

        return $player;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url);

        return $this->json();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sendJson(string $method, string $url, array $payload): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body, (string) $this->client->getResponse()->getContent());

        return $body;
    }
}
