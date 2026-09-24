<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\RecycleOperation;
use App\Entity\UserBooster;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\FeatureEnum;
use App\Tests\FeatureFlagTrait;
use App\Twig\Components\RecycleHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RecyclingFeatureFlagTest extends WebTestCase
{
    use FeatureFlagTrait;
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju owns no fixture cards or boosters
    private const string USER = '195659530363731968';

    public function testThePageIsAvailableWhileOn(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);
        $this->setFeature(FeatureEnum::RECYCLING, true);

        $client->request('GET', '/recyclage');

        self::assertResponseIsSuccessful();
    }

    public function testThePageIsANotFoundWhileOff(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);
        $this->setFeature(FeatureEnum::RECYCLING, false);

        $client->request('GET', '/recyclage');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCollectionHidesTheLinkWhileOff(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);

        $client->request('GET', '/collection');
        $this->assertStringContainsString('data-testid="recycle-link"', (string) $client->getResponse()->getContent());

        $this->setFeature(FeatureEnum::RECYCLING, false);
        $client->request('GET', '/collection');

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('data-testid="recycle-link"', (string) $client->getResponse()->getContent());
    }

    public function testAForgedRecycleActionIsRefusedWhileOff(): void
    {
        $client = self::createClient();
        $user = $this->authenticateClient($client, self::USER);
        [$card, $booster] = $this->createDuplicate($user);
        $cardId = (string) $card->getId();

        // selection built while the feature was still on
        $component = $this->createLiveComponent(RecycleHub::class, client: $client);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->call('addCopy', ['cardId' => $cardId, 'kind' => 'normal']);
        $component->set('boosterId', (string) $booster->getId());

        $this->setFeature(FeatureEnum::RECYCLING, false);

        try {
            $component->call('recycle');
            $this->fail('The action must answer 404 while recycling is off.');
        } catch (NotFoundHttpException) {
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $userCard = $entityManager->getRepository(UserCard::class)->findOneBy(['discordUser' => $user->getDiscordId(), 'card' => $cardId]);
        $this->assertSame(3, $userCard?->getQuantity(), 'Nothing is debited.');
        $this->assertNull($entityManager->getRepository(UserBooster::class)->findOneBy(['discordUser' => $user->getDiscordId(), 'booster' => $booster->getId()]));
        $this->assertSame(0, $entityManager->getRepository(RecycleOperation::class)->count(['discordUser' => $user->getDiscordId()]));
    }

    public function testAComponentReRenderIsRefusedWhileOff(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::USER);
        $component = $this->createLiveComponent(RecycleHub::class, client: $client);

        $this->setFeature(FeatureEnum::RECYCLING, false);

        $this->expectException(NotFoundHttpException::class);
        $component->refresh();
    }

    /**
     * @return array{Card, Booster} a legendary owned 3 times: 2 copies = one booster
     */
    private function createDuplicate(DiscordUser $user): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Flag recycle extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $card = new Card()
            ->setName('Flag recycle card ' . uniqid())
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::LEGENDARY)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $entityManager->persist($card);
        $entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity(3)->setHoloQuantity(0));

        $booster = new Booster()
            ->setName('Flag recycle pack ' . uniqid())
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['legendary' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $entityManager->persist($booster);
        $entityManager->flush();

        return [$card, $booster];
    }
}
