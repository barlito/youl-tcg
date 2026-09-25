<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\TradeOffer;
use App\Entity\TradeOfferLine;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Trade\TradeOfferSideEnum;
use App\Enum\Trade\TradeOfferStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Trade audit screen: every line in clear for admins, never editable, never
 * reachable by a player.
 */
final class AdminTradeOfferCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/trade-offer';

    private const string JUJU = '195659530363731968';

    private const string BENJ = '232457563910832129';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testIndexListsTheOffersWithTheirPlayersAndStatus(): void
    {
        $this->authenticateClient($this->client);
        $this->createOffer(TradeOfferStatusEnum::REFUSED, 'Rukia');

        $crawler = $this->client->request('GET', self::CRUD_URL);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table.datagrid')->text();
        $this->assertStringContainsString('Juju', $text);
        $this->assertStringContainsString('Benj', $text);
        $this->assertStringContainsString('Refusée', $text);
        $this->assertStringContainsString('2 offertes', $text);
        $this->assertCount(0, $crawler->filter('.action-new, .action-edit, .action-delete'));
    }

    public function testDetailShowsEveryLineInClear(): void
    {
        $this->authenticateClient($this->client);
        $offer = $this->createOffer(TradeOfferStatusEnum::CANCELLED, 'Kenpachi');

        $crawler = $this->client->request('GET', self::CRUD_URL . '/' . $offer->getId());

        self::assertResponseIsSuccessful();
        $lines = $crawler->filter('.trade-lines');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Offert par Juju', $lines->text());
        $this->assertStringContainsString('Demandé à Benj', $lines->text());
        // admins audit: the card names are never masked here
        $this->assertStringContainsString('Kenpachi offered', $lines->text());
        $this->assertStringContainsString('Kenpachi requested', $lines->text());
        $this->assertStringContainsString('✨ 1', $lines->text());
    }

    public function testTheStatusFilterNarrowsTheList(): void
    {
        $this->authenticateClient($this->client);
        $this->createOffer(TradeOfferStatusEnum::ACCEPTED, 'Accepted one');
        $this->createOffer(TradeOfferStatusEnum::REFUSED, 'Refused one');

        $crawler = $this->client->request('GET', self::CRUD_URL, ['filters' => ['status' => ['comparison' => '=', 'value' => 'accepted']]]);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table.datagrid tbody')->text();
        $this->assertStringContainsString('Acceptée', $text);
        $this->assertStringNotContainsString('Refusée', $text);
    }

    public function testThePlayerFilterNarrowsTheList(): void
    {
        $this->authenticateClient($this->client);
        $this->createOffer(TradeOfferStatusEnum::ACCEPTED, 'Kept');

        $crawler = $this->client->request('GET', self::CRUD_URL, ['filters' => ['proposer' => ['comparison' => '=', 'value' => self::BENJ]]]);

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Juju', $crawler->filter('table.datagrid tbody')->text());
    }

    public function testSortingOnEverySortableColumnRenders(): void
    {
        $this->authenticateClient($this->client);
        $this->createOffer(TradeOfferStatusEnum::ACCEPTED, 'Sorted');

        foreach (['createdAt', 'proposer.username', 'receiver.username', 'resolvedAt'] as $column) {
            $this->client->request('GET', self::CRUD_URL, ['sort' => [$column => 'ASC']]);
            self::assertResponseIsSuccessful($column);
        }
    }

    public function testWritesAreForbidden(): void
    {
        $this->authenticateClient($this->client);
        $offer = $this->createOffer(TradeOfferStatusEnum::PENDING, 'Locked');

        $this->client->request('GET', self::CRUD_URL . '/' . $offer->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::CRUD_URL . '/' . $offer->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPlayersCannotReachIt(): void
    {
        $this->authenticateClient($this->client, self::JUJU);

        $this->client->request('GET', self::CRUD_URL);

        self::assertResponseStatusCodeSame(403);
    }

    private function createOffer(TradeOfferStatusEnum $status, string $cardPrefix): TradeOffer
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $juju = $entityManager->find(DiscordUser::class, self::JUJU);
        $benj = $entityManager->find(DiscordUser::class, self::BENJ);
        \assert($juju instanceof DiscordUser && $benj instanceof DiscordUser);

        $extension = new Extension()->setName('Admin trade ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $entityManager->persist($extension);

        $offer = new TradeOffer()->setProposer($juju)->setReceiver($benj);
        foreach ([TradeOfferSideEnum::OFFERED, TradeOfferSideEnum::REQUESTED] as $side) {
            $card = new Card()
                ->setName(\sprintf('%s %s', $cardPrefix, $side->value))
                ->setDescription('Test')
                ->setExtension($extension)
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity(CardRarityEnum::RARE)
            ;
            $entityManager->persist($card);
            $offer->addLine(new TradeOfferLine()->setSide($side)->setCard($card)->setNormalQuantity(1)->setHoloQuantity(TradeOfferSideEnum::OFFERED === $side ? 1 : 0));
        }
        if ($status->isFinal()) {
            $offer->resolve($status, new \DateTimeImmutable());
        }

        $entityManager->persist($offer);
        $entityManager->flush();

        return $offer;
    }
}
