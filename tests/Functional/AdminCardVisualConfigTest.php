<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Card CRUD must expose the very same visual settings as the Extension one
 * (the extension help promises a per-card override), all through widgets — the
 * JSON editor that silently wiped the configuration on a typo is gone.
 */
final class AdminCardVisualConfigTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/card';

    public function testFormExposesOneWidgetPerVisualKeyAndNoJsonEditor(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[name="Card[visualConfigOverrideJson]"]'), 'Les overrides ne doivent plus être éditables en JSON.');
        foreach (['holoEffect', 'foilTexture', 'foilSize', 'glowColor', 'borderColor', 'cssClass'] as $widget) {
            $this->assertCount(1, $crawler->filter(\sprintf('[name="Card[%s]"]', $widget)), $widget);
        }
    }

    public function testEmptySelectsReadAsInheritFromTheUniverse(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $placeholder = (string) $crawler->filter('select[name="Card[holoEffect]"] option')->first()->text();
        $this->assertStringContainsString('hériter', $placeholder);
    }

    public function testSavingTheFormStoresEveryVisualKeyAsAnOverride(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $card = $this->persistedCard($entityManager);
        $id = (string) $card->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[holoEffect]'] = 'cosmos';
        $form['Card[foilTexture]'] = 'ancient';
        $form['Card[foilSize]'] = '45';
        $form['Card[glowColor]'] = '#a435f0';
        $form['Card[borderColor]'] = '#ff3db0';
        $form['Card[cssClass]'] = 'promo-2026';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $saved = $entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $saved);
        $this->assertSame([
            'glow' => '#a435f0',
            'borderColor' => '#ff3db0',
            'cssClass' => 'promo-2026',
            'holoEffect' => 'cosmos',
            'foilTexture' => 'ancient',
            'foilSize' => 45,
        ], $saved->getVisualConfigOverride()->toArray());
    }

    public function testClearingAWidgetGivesTheKeyBackToTheExtension(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $card = $this->persistedCard($entityManager);
        $card->setVisualConfigOverride(new VisualConfig(glow: '#ffffff', cssClass: 'promo-2026', holoEffect: CardEffectEnum::COSMOS));
        $entityManager->flush();
        $id = (string) $card->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[glowColor]'] = '';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $saved = $entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $saved);
        $override = $saved->getVisualConfigOverride();
        $this->assertNull($override->glow, 'Un champ vidé doit redonner la main à l\'univers.');
        $this->assertSame('promo-2026', $override->cssClass);
        $this->assertSame(CardEffectEnum::COSMOS, $override->holoEffect);
    }

    public function testAnInvalidColourIsRejectedAndLeavesTheConfigurationIntact(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $card = $this->persistedCard($entityManager);
        $card->setVisualConfigOverride(new VisualConfig(glow: '#a435f0', cssClass: 'promo-2026'));
        $entityManager->flush();
        $id = (string) $card->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[glowColor]'] = 'not a colour';
        $client->submit($form);

        $entityManager->clear();
        $saved = $entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $saved);
        $override = $saved->getVisualConfigOverride();
        $this->assertSame('#a435f0', $override->glow);
        $this->assertSame('promo-2026', $override->cssClass);
    }

    private function persistedCard(EntityManagerInterface $entityManager): Card
    {
        $extension = new Extension()
            ->setName('Visual card extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $card = new Card()
            ->setName('Visual card ' . uniqid())
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::DRAFT)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $entityManager->persist($extension);
        $entityManager->persist($card);
        $entityManager->flush();

        return $card;
    }
}
