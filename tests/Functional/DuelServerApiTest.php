<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Deck;
use App\Entity\DiscordUser;
use App\Entity\UserCard;
use App\Enum\FeatureEnum;
use App\Repository\DiscordUserRepository;
use App\Security\DuelServerAuthenticator;
use App\Tests\DuelTestTrait;
use App\Tests\FeatureFlagTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Uid\Uuid;

final class DuelServerApiTest extends WebTestCase
{
    use DuelTestTrait;
    use FeatureFlagTrait;
    use JwtAuthTrait;

    private const string TOKEN = 'test-duel-server-token';

    private const string JUJU = '195659530363731968';

    private const string BENJ = '232457563910832129';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $player;

    /**
     * @var list<Card>
     */
    private array $cards;

    private Card $terrain;

    private Deck $deck;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->player = $this->find(self::JUJU);
        $extension = $this->duelExtension();
        $this->cards = $this->ownedCards($this->player, $extension);
        $this->terrain = $this->duelCard($extension, 'Spatio-gare', terrain: true);
        $this->give($this->player, $this->terrain);
        $this->deck = new Deck($this->player, 'Machines', $this->cards, $this->terrain);
        $this->entityManager->persist($this->deck);
        $this->entityManager->flush();
    }

    public function testAFullyOwnedDeckIsServedToTheGameServer(): void
    {
        $body = $this->fetch((string) $this->deck->getId(), self::JUJU);

        self::assertResponseIsSuccessful();
        $expected = $this->ids($this->cards);
        sort($expected);
        $this->assertSame([
            'id' => (string) $this->deck->getId(),
            'name' => 'Machines',
            'cards' => $expected,
            'terrain' => (string) $this->terrain->getId(),
        ], $body);
    }

    public function testAnIncompleteDeckAnswers409WithTheMissingCards(): void
    {
        $row = $this->entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $this->player, 'card' => $this->terrain]);
        $this->assertInstanceOf(UserCard::class, $row);
        $row->setQuantity(0);
        $this->entityManager->flush();

        $body = $this->fetch((string) $this->deck->getId(), self::JUJU);

        self::assertResponseStatusCodeSame(409);
        $this->assertSame([(string) $this->terrain->getId()], $body['missingCards']);
        $this->assertSame(['Ton terrain n\'est plus dans ta collection.'], $body['issues']);
    }

    public function testAnotherPlayerOrAnUnknownDeckIs404(): void
    {
        foreach ([[(string) $this->deck->getId(), self::BENJ], [(string) $this->deck->getId(), '999'], [(string) Uuid::v7(), self::JUJU], ['nope', self::JUJU]] as [$id, $player]) {
            $body = $this->fetch($id, $player);
            self::assertResponseStatusCodeSame(404);
            $this->assertSame(['error' => 'Deck introuvable pour ce joueur.'], $body);
        }

        $this->fetch((string) $this->deck->getId(), '');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAWrongOrMissingTokenIs401(): void
    {
        foreach ([[], ['HTTP_AUTHORIZATION' => 'Bearer wrong'], ['HTTP_AUTHORIZATION' => self::TOKEN]] as $server) {
            $this->client->request('GET', '/api/duel/server/decks/' . $this->deck->getId() . '?player=' . self::JUJU, server: $server);
            self::assertResponseStatusCodeSame(401);
            $this->assertSame('{"error":"Jeton serveur manquant ou invalide."}', $this->client->getResponse()->getContent());
        }
    }

    public function testAPlayerJwtDoesNotOpenTheServerApi(): void
    {
        $this->authenticateClient($this->client, self::JUJU);

        $this->client->request('GET', '/api/duel/server/decks/' . $this->deck->getId() . '?player=' . self::JUJU);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnEmptySecretRefusesEverything(): void
    {
        $authenticator = new DuelServerAuthenticator('');
        $request = Request::create('/api/duel/server/decks/x');
        $request->headers->set('Authorization', 'Bearer ');

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate($request);
    }

    public function testItIs404WhileTheFeatureIsOff(): void
    {
        $this->setFeature(FeatureEnum::DUEL, false);

        $body = $this->fetch((string) $this->deck->getId(), self::JUJU);

        self::assertResponseStatusCodeSame(404);
        $this->assertSame(['error' => 'Introuvable.'], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $id, string $player): array
    {
        $this->client->request('GET', '/api/duel/server/decks/' . $id . '?player=' . $player, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function find(string $discordId): DiscordUser
    {
        $player = static::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        $this->assertInstanceOf(DiscordUser::class, $player);

        return $player;
    }
}
