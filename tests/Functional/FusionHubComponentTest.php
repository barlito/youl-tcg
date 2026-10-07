<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\FusionOperation;
use App\Entity\MarketListing;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\FusionHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class FusionHubComponentTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju owns no fixture cards
    private const string USER = '195659530363731968';

    public function testThePageListsOnlyCardsWithEnoughNormalCopies(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10], ['quantity' => 9], ['quantity' => 12, 'holo' => 3]]);

        $client->request('GET', '/fusion');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString($cards[0]->getName(), $content);
        $this->assertStringNotContainsString($cards[1]->getName(), $content, '9 normal copies are not enough.');
        $this->assertStringNotContainsString($cards[2]->getName(), $content, 'Holo copies do not count as normal ones.');
        $this->assertStringContainsString('10 normales → 1 holo', $content);
    }

    public function testTheEmptyStateShowsWithoutAnyFusableCard(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/fusion');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('data-testid="fusion-empty"', (string) $client->getResponse()->getContent());
    }

    public function testOnlyThePublishedCatalogueAndNoUniqueIsListed(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10], ['quantity' => 10], ['quantity' => 10]]);
        $cards[1]->setStatus(CardStatusEnum::DRAFT);
        $cards[2]->setUnique(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/fusion');

        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString($cards[0]->getName(), $content);
        $this->assertStringNotContainsString($cards[1]->getName(), $content);
        $this->assertStringNotContainsString($cards[2]->getName(), $content);
    }

    public function testFusingNeedsTwoClicksAndCreditsTheHolo(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 23]]);
        $cardId = (string) $cards[0]->getId();

        $component = $this->createLiveComponent(FusionHub::class, client: $client);
        $component->call('askFuse', ['cardId' => $cardId]);

        $this->assertSame(0, $this->userCard($user, $cards[0])->getHoloQuantity(), 'Asking debits nothing.');
        $this->assertSame(23, $this->userCard($user, $cards[0])->getQuantity());
        $crawler = $component->render()->crawler();
        $this->assertStringContainsString('10 normales → 1', $crawler->filter('[data-testid="fusion-confirmation"]')->text());

        $component->call('changeTimes', ['delta' => 5]);
        $this->assertSame(2, $component->component()->times, 'Clamped to the fusions the stock allows.');
        $component->call('fuse');

        $row = $this->userCard($user, $cards[0]);
        $this->assertSame(23 - 20 + 2, $row->getQuantity());
        $this->assertSame(2, $row->getHoloQuantity());
        $this->assertNull($component->component()->confirming);
        $this->assertStringContainsString('fusionnées en 2 holo', (string) $component->component()->success);
        $this->assertCount(1, $component->render()->crawler()->filter('[data-testid="fusion-success"]'));
    }

    public function testAbortCancelsTheConfirmation(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10]]);

        $component = $this->createLiveComponent(FusionHub::class, client: $client);
        $component->call('askFuse', ['cardId' => (string) $cards[0]->getId()]);
        $component->call('abort');

        $this->assertNull($component->component()->confirming);
        $component->call('fuse');
        $this->assertSame(10, $this->userCard($user, $cards[0])->getQuantity());
        $this->assertNotNull($component->component()->error);
    }

    public function testACardWithoutEnoughFreeCopiesCannotBeAsked(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10]]);
        self::getContainer()->get(EntityManagerInterface::class)->persist(new MarketListing($user, $cards[0], false, 10));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $component = $this->createLiveComponent(FusionHub::class, client: $client);
        $component->call('askFuse', ['cardId' => (string) $cards[0]->getId()]);

        $this->assertNull($component->component()->confirming);
        $crawler = new Crawler((string) $component->render());
        $this->assertSame(1, $crawler->filter('[data-testid="engaged-badge"]')->count());
        $this->assertStringContainsString('en vente', $crawler->filter('[data-testid="engaged-badge"]')->text());
        $this->assertCount(0, $crawler->filter('[data-testid="fusion-ask"]'));
    }

    public function testACardListedAfterTheConfirmationIsRefusedByTheService(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10]]);

        $component = $this->createLiveComponent(FusionHub::class, client: $client);
        $component->call('askFuse', ['cardId' => (string) $cards[0]->getId()]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new MarketListing(
            $entityManager->getReference(DiscordUser::class, $user->getDiscordId()),
            $entityManager->getReference(Card::class, $cards[0]->getId()),
            false,
            10,
        ));
        $entityManager->flush();
        $component->call('fuse');

        $this->assertNotNull($component->component()->error);
        $this->assertSame(10, $this->userCard($user, $cards[0])->getQuantity());
        $this->assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(FusionOperation::class)->count(['discordUser' => $user]));
    }

    public function testTheCollectionShowsTheLinkOnlyWhileOn(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/collection');
        $this->assertStringContainsString('data-testid="fusion-link"', (string) $client->getResponse()->getContent());

        $this->setFeature(FeatureEnum::FUSION, false);
        $client->request('GET', '/collection');
        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('data-testid="fusion-link"', (string) $client->getResponse()->getContent());
    }

    public function testThePageIsANotFoundWhileOff(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);
        $this->setFeature(FeatureEnum::FUSION, false);

        $client->request('GET', '/fusion');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedFuseActionIsRefusedWhileOff(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        $cards = $this->createCards($user, [['quantity' => 10]]);

        $component = $this->createLiveComponent(FusionHub::class, client: $client);
        $component->call('askFuse', ['cardId' => (string) $cards[0]->getId()]);
        $this->setFeature(FeatureEnum::FUSION, false);

        try {
            $component->call('fuse');
            $this->fail('The action must answer 404 while fusion is off.');
        } catch (NotFoundHttpException) {
        }

        $this->assertSame(10, $this->userCard($user, $cards[0])->getQuantity());
    }

    public function testAComponentReRenderIsRefusedWhileOff(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);
        $component = $this->createLiveComponent(FusionHub::class, client: $client);

        $this->setFeature(FeatureEnum::FUSION, false);

        $this->expectException(NotFoundHttpException::class);
        $component->refresh();
    }

    private function userCard(DiscordUser $user, Card $card): UserCard
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $userCard = $entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $user->getDiscordId(), 'card' => $card->getId()]);
        $this->assertNotNull($userCard);

        return $userCard;
    }

    /**
     * @param list<array{quantity: int, holo?: int}> $owned
     *
     * @return list<Card>
     */
    private function createCards(DiscordUser $user, array $owned): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Fusion page extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $cards = [];
        foreach ($owned as $index => $definition) {
            $card = new Card()
                ->setName(\sprintf('Fusion page card %d %s', $index, uniqid()))
                ->setDescription('Test')
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity(CardRarityEnum::COMMON)
                ->setExtension($extension)
            ;
            $card->setImageName('default_card.png');
            $entityManager->persist($card);
            $entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($definition['quantity'])->setHoloQuantity($definition['holo'] ?? 0));
            $cards[] = $card;
        }
        $entityManager->flush();

        return $cards;
    }
}
