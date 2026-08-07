<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The inventory and audit tables have no ON DELETE on purpose: a referenced
 * booster or universe must survive a delete attempt, and the admin must be told
 * why rather than shown a bare database error.
 */
final class AdminDeletionGuardTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->followRedirects();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client);
    }

    public function testAnUnreferencedBoosterIsDeletedNormally(): void
    {
        $booster = $this->createBooster();
        $id = (string) $booster->getId();

        $this->submitDelete('/admin/booster/' . $id);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $this->assertNull($this->entityManager->find(Booster::class, $id));
    }

    public function testAReferencedExtensionSurvivesAndExplainsWhy(): void
    {
        // an extension owning a booster cannot go: the booster would be orphaned
        $booster = $this->createBooster();
        $extension = $booster->getExtension();
        $this->assertInstanceOf(Extension::class, $extension);
        $id = (string) $extension->getId();

        $crawler = $this->submitDelete('/admin/extension/' . $id);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $this->assertInstanceOf(Extension::class, $this->entityManager->find(Extension::class, $id));
        $this->assertStringContainsString(
            'booster(s) lui appartiennent',
            $crawler->filter('body')->text(),
            'The admin must be told what still points at the universe.',
        );
    }

    private function submitDelete(string $entityUrl): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $entityUrl);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');

        return $this->client->request('POST', $entityUrl . '/delete', ['token' => $token]);
    }

    private function createBooster(): Booster
    {
        $extension = new Extension()
            ->setName('Deletion guard universe ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->entityManager->persist($booster);
        $this->entityManager->flush();

        return $booster;
    }
}
