<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminBoosterCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/booster';

    public function testNewPageRendersTheSlotCollectionInsteadOfARawJsonEditor(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-ea-collection-field] .field-collection-add-button'));
        $this->assertCount(0, $crawler->filter('textarea[name*="rarityRatesJson"]'));
    }

    public function testEditPageRendersOneInputPerRarityOfEverySlot(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster([
            ['rarities' => ['common' => 70, 'rare' => 30], 'holoChance' => 20],
            ['rarities' => ['rare' => 100], 'holoChance' => 100],
        ]);

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $booster->getId() . '/edit');

        self::assertResponseIsSuccessful();
        foreach (CardRarityEnum::cases() as $rarity) {
            $this->assertCount(
                1,
                $crawler->filter(\sprintf('input[name="Booster[rarityRates][0][rarities][%s]"]', $rarity->value)),
                \sprintf('The first slot must expose a "%s" weight input.', $rarity->value),
            );
        }

        $this->assertSame('70', $crawler->filter('input[name="Booster[rarityRates][0][rarities][common]"]')->attr('value'));
        $this->assertSame('', (string) $crawler->filter('input[name="Booster[rarityRates][0][rarities][legendary]"]')->attr('value'));
        $this->assertSame('20', $crawler->filter('input[name="Booster[rarityRates][0][holoChance]"]')->attr('value'));
        $this->assertSame('100', $crawler->filter('input[name="Booster[rarityRates][1][rarities][rare]"]')->attr('value'));
    }

    public function testSubmittingTheFormStoresACleanSlotList(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster([
            ['rarities' => ['common' => 70, 'rare' => 30], 'holoChance' => 20],
            ['rarities' => ['rare' => 100], 'holoChance' => 100],
        ]);
        $boosterId = $booster->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $boosterId . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#edit-Booster-form')->form();
        $values = $form->getPhpValues();
        // slot #2 deleted in the browser and a new one appended: the submitted
        // keys are gapped, the stored JSON must still be a list
        $values['Booster']['rarityRates'] = [
            0 => ['rarities' => ['common' => '80', 'uncommon' => '', 'rare' => '20', 'legendary' => ''], 'holoChance' => '15'],
            2 => ['rarities' => ['common' => '', 'uncommon' => '', 'rare' => '', 'legendary' => '1'], 'holoChance' => ''],
        ];
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->getRepository(Booster::class)->find($boosterId);
        \assert($saved instanceof Booster);

        $this->assertSame([
            ['rarities' => ['common' => 80, 'rare' => 20], 'holoChance' => 15],
            ['rarities' => ['legendary' => 1], 'holoChance' => 0],
        ], $saved->getRarityRates());
        $this->assertSame(2, $saved->getCardCount());
    }

    public function testASlotWithoutAnyWeightIsRejected(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $booster = $this->createBooster([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $boosterId = $booster->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $boosterId . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#edit-Booster-form')->form();
        $values = $form->getPhpValues();
        $values['Booster']['rarityRates'] = [
            0 => ['rarities' => ['common' => '', 'uncommon' => '', 'rare' => '', 'legendary' => ''], 'holoChance' => '10'],
        ];
        $crawler = $client->request('POST', $form->getUri(), $values);

        $this->assertStringContainsString('au moins une rareté', $crawler->filter('body')->text());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $saved = $entityManager->getRepository(Booster::class)->find($boosterId);
        \assert($saved instanceof Booster);
        $this->assertSame([['rarities' => ['common' => 100], 'holoChance' => 0]], $saved->getRarityRates());
    }

    public function testDetailPageShowsTheResultingDropRates(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster([['rarities' => ['common' => 60, 'rare' => 40], 'holoChance' => 25]]);

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $booster->getId());

        self::assertResponseIsSuccessful();
        $rates = $crawler->filter('.booster-drop-rates')->text();
        $this->assertStringContainsString('Commune 60 %', $rates);
        $this->assertStringContainsString('Rare 40 %', $rates);
        $this->assertStringContainsString('poids 60', $rates);
        $this->assertStringContainsString('holo 25 %', $rates);
    }

    public function testIndexPageShowsADropRatesColumn(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $this->createBooster([['rarities' => ['common' => 100], 'holoChance' => 0]]);

        $crawler = $client->request('GET', self::CRUD_URL);

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('.booster-drop-rates')->count());
        $this->assertStringContainsString('Commune 100 %', $crawler->filter('body')->text());
    }

    public function testARarityWithoutAnyDrawableCardIsFlaggedButNotBlocking(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        // the extension only publishes commons: the legendary weight can never
        // be honoured and the draw would silently fall back to a lower tier
        $booster = $this->createBooster(
            [['rarities' => ['common' => 90, 'legendary' => 10], 'holoChance' => 0]],
            publishedRarities: [CardRarityEnum::COMMON],
        );

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $booster->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('sans carte tirable', $crawler->filter('body')->text());

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $booster->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('⚠ Légendaire', $crawler->filter('.booster-drop-rates')->text());
    }

    /**
     * @param list<array{rarities: array<string, int>, holoChance: int}> $rarityRates
     * @param list<CardRarityEnum>                                       $publishedRarities
     */
    private function createBooster(array $rarityRates, array $publishedRarities = []): Booster
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Booster crud extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        foreach ($publishedRarities as $rarity) {
            $card = new Card()
                ->setName('Card ' . $rarity->value . ' ' . uniqid())
                ->setDescription('Test')
                ->setExtension($extension)
                ->setStatus(CardStatusEnum::PUBLISHED)
                ->setRarity($rarity)
            ;
            $entityManager->persist($card);
        }

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates($rarityRates)
        ;
        $entityManager->persist($booster);
        $entityManager->flush();

        return $booster;
    }
}
