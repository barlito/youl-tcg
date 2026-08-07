<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\VisualConfig;
use App\Entity\Extension;
use App\Enum\Card\CardEffectEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminExtensionCrudTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string CRUD_URL = '/admin/extension';

    public function testNewPageRendersWithTheImageUploadField(): void
    {
        // regression guard: EasyAdmin reads every field of the EMPTY entity on
        // the "new" form — a Vich field wired to a strict getter would 500
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type="file"][name*="imageFile"]'));
    }

    public function testEditPageRendersWithTheImageUploadField(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Crud test extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $entityManager->persist($extension);
        $entityManager->flush();

        $crawler = $client->request('GET', \sprintf('%s/%s/edit', self::CRUD_URL, $extension->getId()));

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type="file"][name*="imageFile"]'));
    }

    public function testFormExposesOneWidgetPerVisualKeyAndNoJsonEditor(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[name="Extension[visualConfigJson]"]'), 'La config visuelle ne doit plus être éditable en JSON.');
        foreach (['holoEffect', 'foilTexture', 'foilSize', 'glowColor', 'borderColor', 'cssClass'] as $widget) {
            $this->assertCount(1, $crawler->filter(\sprintf('[name="Extension[%s]"]', $widget)), $widget);
        }
        // a colour must keep an empty state ("hériter"), which <input type="color"> has not
        $this->assertSame('text', $crawler->filter('[name="Extension[glowColor]"]')->attr('type'));
    }

    public function testEnumSelectsOfferAnExplicitEmptyChoiceAndStableValues(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', self::CRUD_URL . '/new');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="Extension[holoEffect]"] option');
        $this->assertSame(['', 'shine', 'basic', 'cosmos', 'trainer'], $options->each(static fn ($node): ?string => $node->attr('value')));
        $this->assertStringContainsString('aucun', (string) $options->first()->text());
    }

    public function testSavingTheFormStoresEveryVisualKeyInTheJsonColumn(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Crud visual extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $entityManager->persist($extension);
        $entityManager->flush();
        $id = (string) $extension->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Extension"]')->form();
        $form['Extension[holoEffect]'] = 'cosmos';
        $form['Extension[foilTexture]'] = 'ancient';
        $form['Extension[foilSize]'] = '45';
        $form['Extension[glowColor]'] = '#a435f0';
        $form['Extension[borderColor]'] = '#ff3db0';
        $form['Extension[cssClass]'] = 'promo-2026';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $saved = $entityManager->find(Extension::class, $id);
        $this->assertInstanceOf(Extension::class, $saved);
        $this->assertSame([
            'glow' => '#a435f0',
            'borderColor' => '#ff3db0',
            'cssClass' => 'promo-2026',
            'holoEffect' => 'cosmos',
            'foilTexture' => 'ancient',
            'foilSize' => 45,
        ], $saved->getVisualConfig()->toArray());
    }

    public function testClearingOneWidgetOnlyDropsItsOwnKey(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Crud inherit extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
            ->setVisualConfig(new VisualConfig(glow: '#a435f0', cssClass: 'promo-2026', holoEffect: CardEffectEnum::COSMOS))
        ;
        $entityManager->persist($extension);
        $entityManager->flush();
        $id = (string) $extension->getId();

        $crawler = $client->request('GET', self::CRUD_URL . '/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="Extension"]')->form();
        $form['Extension[glowColor]'] = '';
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $saved = $entityManager->find(Extension::class, $id);
        $this->assertInstanceOf(Extension::class, $saved);
        $config = $saved->getVisualConfig();
        $this->assertNull($config->glow);
        $this->assertSame('promo-2026', $config->cssClass);
        $this->assertSame(CardEffectEnum::COSMOS, $config->holoEffect);
    }
}
