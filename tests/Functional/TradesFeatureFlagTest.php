<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\TradeLineRequest;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\TradeOffer;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Service\Trade\TradeOfferService;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\TradeComposer;
use App\Twig\Components\TradeInbox;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class TradesFeatureFlagTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string BARLITO = '188967649332428800';

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tradeUrls(): iterable
    {
        yield 'inbox' => ['/echanges'];
        yield 'player picker' => ['/echanges/nouveau'];
        yield 'composer' => ['/echanges/nouveau/' . self::JUJU];
    }

    #[DataProvider('tradeUrls')]
    public function testTradePagesAreNotFoundWhileOff(string $url): void
    {
        $this->authenticateClient($this->client);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->setFeature(FeatureEnum::TRADES, false);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedAcceptIsRefusedWhileOff(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        [$offer, $offered, $requested] = $this->createOffer($barlito, $juju);

        $component = $this->createLiveComponent(TradeInbox::class, client: $this->client);
        $this->setFeature(FeatureEnum::TRADES, false);

        try {
            $component->call('accept', ['offerId' => (string) $offer->getId()]);
            $this->fail('The action must answer 404 while trades are off.');
        } catch (NotFoundHttpException) {
        }

        $this->entityManager->clear();
        $this->assertSame(TradeOfferStatusEnum::PENDING, $this->entityManager->find(TradeOffer::class, $offer->getId())?->getStatus());
        $this->assertSame(1, $this->owned($barlito, $offered));
        $this->assertSame(0, $this->owned($juju, $offered));
        $this->assertSame(1, $this->owned($juju, $requested));
        $this->assertSame(0, $this->owned($barlito, $requested));
    }

    public function testTheComposerIsRefusedWhileOff(): void
    {
        $this->authenticateClient($this->client);
        $component = $this->createLiveComponent(TradeComposer::class, data: ['counterpartId' => self::JUJU], client: $this->client);
        $this->setFeature(FeatureEnum::TRADES, false);

        $this->expectException(NotFoundHttpException::class);
        $component->call('submit');
    }

    public function testTheHeaderHasNeitherLinkNorBadgeWhileOff(): void
    {
        $barlito = $this->user(self::BARLITO);
        $juju = $this->authenticateClient($this->client, self::JUJU);
        $this->createOffer($barlito, $juju);

        $crawler = $this->client->request('GET', '/');
        $this->assertCount(1, $crawler->filter('header a[href="/echanges"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="pending-trades-badge"]'));

        $this->setFeature(FeatureEnum::TRADES, false);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a[href="/echanges"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="pending-trades-badge"]'));
        $this->assertStringContainsString('Échanges', $crawler->filter('header nav')->text(), 'The « bientôt » entry is back.');
    }

    private function user(string $discordId): DiscordUser
    {
        $user = self::getContainer()->get(DiscordUserRepository::class)->find($discordId);
        \assert($user instanceof DiscordUser);

        return $user;
    }

    /**
     * @return array{TradeOffer, Card, Card}
     */
    private function createOffer(DiscordUser $proposer, DiscordUser $receiver): array
    {
        $extension = new Extension()
            ->setName('Trade flag extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->entityManager->persist($extension);
        $offered = $this->giveCard($proposer, $extension);
        $requested = $this->giveCard($receiver, $extension);

        $offer = self::getContainer()->get(TradeOfferService::class)->create(
            $proposer,
            $receiver,
            [new TradeLineRequest($offered, 1)],
            [new TradeLineRequest($requested, 1)],
        );

        return [$offer, $offered, $requested];
    }

    private function giveCard(DiscordUser $user, Extension $extension): Card
    {
        $card = new Card()
            ->setName('Trade flag card ' . uniqid())
            ->setDescription('Test')
            ->setExtension($extension)
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
        ;
        $this->entityManager->persist($card);
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity(1)->setHoloQuantity(0));
        $this->entityManager->flush();

        return $card;
    }

    private function owned(DiscordUser $user, Card $card): int
    {
        return $this->entityManager->getRepository(UserCard::class)
            ->findOneBy(['discordUser' => $user->getDiscordId(), 'card' => $card->getId()])?->getQuantity() ?? 0
        ;
    }
}
