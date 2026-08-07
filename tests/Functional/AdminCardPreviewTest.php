<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Dto\VisualConfig;
use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Card\CardNameFontEnum;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The back-office preview is the reference screen to tune a card: it must
 * render the real front component with the values being edited — the whole
 * edit form is forwarded under its own field names — and it must never write
 * anything to the database.
 */
final class AdminCardPreviewTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client);
    }

    public function testPreviewRendersTheRealComponentWithTheFrameAssets(): void
    {
        $card = $this->persistedCard();

        $crawler = $this->client->request('GET', $this->previewUrl($card));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('cards/frame', $html, 'La preview doit charger le CSS du cadre.');
        $this->assertStringContainsString('Pirata+One', $html, 'La police par défaut du nom doit être chargée.');
        $this->assertCount(1, $crawler->filter('.card.framed'));
        $this->assertSame($card->getName(), trim((string) $crawler->filter('.card-frame__name')->text()));
        $this->assertSame($card->getExtension()?->getName(), trim((string) $crawler->filter('.card-frame__ext')->text()));
    }

    public function testTheFormValuesBeingEditedDriveTheRender(): void
    {
        $card = $this->persistedCard();

        $crawler = $this->client->request('GET', $this->previewUrl($card), ['Card' => [
            'name' => 'Nom en cours de saisie',
            'rarity' => CardRarityEnum::LEGENDARY->value,
            'unique' => '1',
            'glowColor' => '#a435f0',
            'borderColor' => '#ff3db0',
            'cssClass' => 'promo-2026',
            'nameFont' => CardNameFontEnum::SPACE_GROTESK->value,
            'frameLineStart' => '#123456',
        ]]);

        self::assertResponseIsSuccessful();
        $node = $crawler->filter('.card');
        $this->assertSame('Nom en cours de saisie', trim((string) $crawler->filter('.card-frame__name')->text()));
        $this->assertSame('legendary', $node->attr('data-rarity'));
        $this->assertStringContainsString('unique', (string) $node->attr('class'));
        $this->assertStringContainsString('promo-2026', (string) $node->attr('class'));

        $style = (string) $node->attr('style');
        $this->assertStringContainsString('--card-glow: #a435f0', $style);
        $this->assertStringContainsString('--card-border: #ff3db0', $style);
        $this->assertStringContainsString('--frame-name-font: \'Space Grotesk\', sans-serif', $style);
        $this->assertStringContainsString('--frame-line-1: #123456', $style);
    }

    public function testAnInvalidColourNeverReachesTheStyleAttribute(): void
    {
        $card = $this->persistedCard();

        $crawler = $this->client->request('GET', $this->previewUrl($card), ['Card' => [
            'name' => $card->getName(),
            'glowColor' => 'rouge; background: url(x)',
        ]]);

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('--card-glow', (string) $crawler->filter('.card')->attr('style'));
    }

    public function testSwitchingTheUniverseMovesTheWordmarkAndTheInheritedConfig(): void
    {
        $card = $this->persistedCard();
        $other = $this->persistedExtension('Autre univers ' . uniqid());
        $other->setVisualConfig(new VisualConfig(glow: '#00ffcc'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', $this->previewUrl($card), ['Card' => [
            'name' => $card->getName(),
            'extension' => (string) $other->getId(),
        ]]);

        self::assertResponseIsSuccessful();
        $this->assertSame($other->getName(), trim((string) $crawler->filter('.card-frame__ext')->text()));
        $this->assertStringContainsString('--card-glow: #00ffcc', (string) $crawler->filter('.card')->attr('style'));
    }

    public function testTheLiveValuesAreNeverPersisted(): void
    {
        $card = $this->persistedCard();
        $id = (string) $card->getId();

        $this->client->request('GET', $this->previewUrl($card), ['Card' => [
            'name' => 'Jamais sauvegardé',
            'cssClass' => 'jamais-sauvegarde',
        ]]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $saved = $this->entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $saved);
        $this->assertNotSame('Jamais sauvegardé', $saved->getName());
        $this->assertNull($saved->getVisualConfigOverride()->cssClass);
    }

    public function testAnUnknownCardIsNotFound(): void
    {
        $this->client->request('GET', '/admin/card-preview/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    private function previewUrl(Card $card): string
    {
        return '/admin/card-preview/' . $card->getId();
    }

    private function persistedCard(): Card
    {
        $card = new Card()
            ->setName('Carte de preview ' . uniqid())
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::DRAFT)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($this->persistedExtension('Univers de preview ' . uniqid()))
        ;
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function persistedExtension(string $name): Extension
    {
        $extension = new Extension()
            ->setName($name)
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);
        $this->entityManager->flush();

        return $extension;
    }
}
