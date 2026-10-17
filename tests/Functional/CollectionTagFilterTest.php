<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\FeatureEnum;
use App\Tests\DuelTestTrait;
use App\Tests\FeatureFlagTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tag chips of « Ma collection » (duel feature): built from the owned cards only, a masked card leaks no tag.
 */
final class CollectionTagFilterTest extends WebTestCase
{
    use DuelTestTrait;
    use FeatureFlagTrait;
    use JwtAuthTrait;

    private KernelBrowser $client;

    private DiscordUser $user;

    private Extension $extension;

    private Card $benj;

    private Card $benjMachine;

    private Card $hiddenBenj;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = $this->authenticateClient($this->client, '195659530363731968');
        $this->extension = $this->duelExtension();
        $this->benj = $this->duelCard($this->extension, 'Benj le fou', tags: ['character:benj']);
        $this->benjMachine = $this->duelCard($this->extension, 'Benj mécano', tags: ['character:benj', 'trait:machine']);
        $this->hiddenBenj = $this->duelCard($this->extension, 'Benj caché', tags: ['character:benj', 'family:secret']);
        $this->give($this->user, $this->benj);
        $this->give($this->user, $this->benjMachine, quantity: 2, holoQuantity: 1);
        $this->duelEntityManager()->flush();
    }

    public function testChipsListTheTagsOfOwnedCardsPerFamily(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug());

        self::assertResponseIsSuccessful();
        $this->assertSame(['character', 'trait'], $crawler->filter('[data-testid="tag-group"]')->each(static fn (Crawler $group): ?string => $group->attr('data-family')));
        $this->assertSame(['character:benj', 'trait:machine'], $crawler->filter('[data-testid="tag-chip"]')->each(static fn (Crawler $chip): ?string => $chip->attr('data-tag')));
        $this->assertStringContainsString('Benj 2', $crawler->filter('[data-tag="character:benj"]')->text());
        $this->assertStringNotContainsString('secret', (string) $this->client->getResponse()->getContent(), 'A masked card never reveals its tags.');
    }

    public function testATagKeepsOnlyTheOwnedCardsCarryingIt(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug() . '?tag=trait:machine');

        self::assertResponseIsSuccessful();
        $tiles = $crawler->filter('[data-testid="collection-tile"]');
        $this->assertCount(1, $tiles);
        $this->assertStringContainsString('Benj mécano', $tiles->text());
        $this->assertSame('true', $crawler->filter('[data-tag="trait:machine"]')->attr('aria-pressed'));
        $this->assertCount(1, $crawler->filter('[data-testid="tag-reset"]'));

        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug() . '?tag=character:benj');
        $this->assertCount(2, $crawler->filter('[data-testid="collection-tile"]'), 'The unowned Benj stays out of the filtered grid.');
    }

    public function testATagWithoutOwnedCardShowsAnEmptyStateAndAMalformedOneIsIgnored(): void
    {
        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug() . '?tag=family:secret');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="collection-tile"]'));
        $this->assertStringContainsString('Aucune carte de ta collection ne porte ce tag ici.', $crawler->filter('[data-testid="empty-state"]')->text());

        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug() . '?tag=<script>');
        self::assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('[data-testid="collection-tile"]'));
    }

    public function testOwnedTerrainsWearABadge(): void
    {
        $this->give($this->user, $this->duelCard($this->extension, 'Niveau 24', terrain: true));
        $this->duelCard($this->extension, 'Niveau 330', terrain: true);
        $this->duelEntityManager()->flush();

        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug());

        $this->assertCount(1, $crawler->filter('[data-testid="chip-terrain"]'), 'Only the owned terrain, the masked one stays anonymous.');
    }

    public function testNoChipNorFilterWhileTheDuelIsOff(): void
    {
        $this->setFeature(FeatureEnum::DUEL, false);

        $crawler = $this->client->request('GET', '/collection/' . $this->extension->getSlug() . '?tag=trait:machine');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="tag-filters"]'));
        $this->assertCount(3, $crawler->filter('[data-testid="collection-tile"]'));
    }
}
