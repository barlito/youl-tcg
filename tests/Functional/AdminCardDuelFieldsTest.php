<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Tests\DuelTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tags (TomSelect with free creation) and the terrain flag in the card CRUD.
 */
final class AdminCardDuelFieldsTest extends WebTestCase
{
    use DuelTestTrait;
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->followRedirects();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client);
        $extension = $this->duelExtension();
        $this->duelCard($extension, 'Already tagged', tags: ['family:linette']);
        $this->card = $this->duelCard($extension, 'Benj', tags: ['character:benj']);
        $this->entityManager->flush();
    }

    public function testTheEditFormSuggestsKnownTagsAndAllowsNewOnes(): void
    {
        $crawler = $this->client->request('GET', '/admin/card/' . $this->card->getId() . '/edit');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="Card[tags][]"]');
        $this->assertSame('ea-autocomplete', $select->attr('data-ea-widget'));
        $this->assertSame('true', $select->attr('data-ea-autocomplete-allow-item-create'));
        $options = $select->filter('option')->each(static fn (Crawler $option): ?string => $option->attr('value'));
        $this->assertContains('family:linette', $options);
        $this->assertSame(['character:benj'], $select->filter('option[selected]')->each(static fn (Crawler $option): ?string => $option->attr('value')));
        $this->assertCount(1, $crawler->filter('input[name="Card[terrain]"]'));
    }

    public function testANewTagAndTheTerrainFlagAreSaved(): void
    {
        $values = $this->formValues();
        $values['Card']['tags'] = ['character:benj', 'Trait:Machine'];
        $values['Card']['terrain'] = '1';

        $this->client->request('POST', '/admin/card/' . $this->card->getId() . '/edit', $values);

        self::assertResponseIsSuccessful();
        $saved = $this->reload();
        $this->assertSame(['character:benj', 'trait:machine'], $saved->getTags());
        $this->assertTrue($saved->isTerrain());

        $this->client->request('GET', '/admin/card/' . $this->card->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'character:benj, trait:machine');
    }

    public function testAMalformedTagIsRefusedWithAMessage(): void
    {
        $values = $this->formValues();
        $values['Card']['tags'] = ['benj linette'];

        $crawler = $this->client->request('POST', '/admin/card/' . $this->card->getId() . '/edit', $values);

        $this->assertStringContainsString('« benj linette » : format attendu famille:valeur', $crawler->filter('form[name="Card"]')->text());
        $this->assertSame(['character:benj'], $this->reload()->getTags());
    }

    public function testTheUniverseFamilyIsRefusedByTheEntity(): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($this->card->setTags(['universe:kda']));

        $this->assertCount(1, $violations);
        $this->assertSame('tags', $violations[0]->getPropertyPath());
        $this->assertStringContainsString('le tag universe: est implicite', (string) $violations[0]->getMessage());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formValues(): array
    {
        $crawler = $this->client->request('GET', '/admin/card/' . $this->card->getId() . '/edit');
        self::assertResponseIsSuccessful();

        /** @var array<string, array<string, mixed>> $values */
        $values = $crawler->filter('form[name="Card"]')->form()->getPhpValues();

        return $values;
    }

    private function reload(): Card
    {
        $this->entityManager->clear();
        $card = $this->entityManager->find(Card::class, $this->card->getId());
        $this->assertInstanceOf(Card::class, $card);

        return $card;
    }
}
