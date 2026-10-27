<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Card status and rarity read in French everywhere in the back-office, never the raw enum names.
 */
final class AdminCardLabelsTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->authenticateClient($this->client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $extension = new Extension()
            ->setName('Labels universe ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $entityManager->persist($extension);
        $this->card = new Card()
            ->setName('Labels card ' . uniqid())
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::LEGENDARY)
            ->setExtension($extension)
        ;
        $entityManager->persist($this->card);
        $entityManager->flush();
    }

    public function testTheIndexAndTheFiltersUseFrenchLabels(): void
    {
        $crawler = $this->client->request('GET', '/admin/card', [
            'filters' => ['rarity' => ['comparison' => '=', 'value' => 'legendary']],
        ]);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('table.datagrid tbody tr')->reduce(
            fn ($tr): bool => str_contains($tr->text(), $this->card->getName()),
        );
        $this->assertCount(1, $row);
        $this->assertStringContainsString('Publiée', $row->text());
        $this->assertStringContainsString('Légendaire', $row->text());
        $this->assertStringNotContainsString('PUBLISHED', $row->text());
        $this->assertStringNotContainsString('LEGENDARY', $row->text());
        foreach ($crawler->filter('table.datagrid tbody tr') as $tr) {
            $this->assertStringNotContainsString('Commune', $tr->textContent);
        }
    }

    public function testTheEditFormUsesFrenchLabelsAndKeepsTheStoredValues(): void
    {
        $crawler = $this->client->request('GET', '/admin/card/' . $this->card->getId() . '/edit');

        self::assertResponseIsSuccessful();
        $status = $crawler->filter('select[name="Card[status]"] option[value="2"]');
        $this->assertSame('Publiée', trim($status->text()));
        $this->assertSame('Brouillon', trim($crawler->filter('select[name="Card[status]"] option[value="1"]')->text()));
        $this->assertSame('Peu commune', trim($crawler->filter('select[name="Card[rarity]"] option[value="uncommon"]')->text()));
    }

    public function testTheBatchImportListsFrenchRarities(): void
    {
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/admin/cards/batch');

        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('select[id$="_rarity"] option')->each(static fn ($option): string => trim($option->text()));
        $this->assertSame(['Commune', 'Peu commune', 'Rare', 'Légendaire'], $labels);
    }
}
