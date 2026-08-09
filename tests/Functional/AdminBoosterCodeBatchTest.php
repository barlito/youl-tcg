<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminBoosterCodeBatchTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testBatchGeneratesTheRequestedCodesAndListsThem(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster();
        $batchLabel = 'Gamescom ' . uniqid();

        $crawler = $client->request('GET', '/admin/booster-codes/batch');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Générer les codes')->form([
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '5',
            'form[quantity]' => '2',
            'form[maxUses]' => '3',
            'form[expiresAt]' => '2026-12-24T20:00',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('5 code(s) généré(s)', $crawler->filter('body')->text());

        /** @var list<BoosterCode> $codes */
        $codes = self::getContainer()->get(BoosterCodeRepository::class)->findByBatch($batchLabel);
        $this->assertCount(5, $codes);

        foreach ($codes as $code) {
            $this->assertSame($booster->getId(), $code->getBooster()->getId());
            $this->assertSame(2, $code->getQuantity());
            $this->assertSame(3, $code->getMaxUses());
            $this->assertSame(0, $code->getUses());
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', $code->getCode());
            // typed in Paris time, stored in UTC
            $this->assertSame('2026-12-24 19:00:00', $code->getExpiresAt()?->format('Y-m-d H:i:s'));
        }

        // the batch is listed back, formatted, right under the form
        $this->assertStringContainsString($codes[0]->getFormattedCode(), $crawler->filter('body')->text());

        // one copy button per code, plus one for the whole batch
        $this->assertCount(
            6,
            $crawler->filter('[data-copy-value]'),
            'Each code carries a copy button, and the batch has its own.',
        );
    }

    public function testTheCodeListingOffersACopyButton(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $code = new BoosterCode()
            ->setCode('ABCDEFGHJKLM')
            ->setBooster($this->createBooster())
            ->setBatchLabel('Copie ' . uniqid())
        ;
        $entityManager->persist($code);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/booster-code?query=' . urlencode((string) $code->getBatchLabel()));

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-copy-value="ABCD-EFGH-JKLM"]'));
    }

    public function testAnEmptyMaxUsesMeansUnlimited(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster();
        $batchLabel = 'Illimité ' . uniqid();

        $crawler = $client->request('GET', '/admin/booster-codes/batch');
        $form = $crawler->selectButton('Générer les codes')->form([
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '1',
            'form[quantity]' => '1',
            'form[maxUses]' => '',
        ]);
        $client->submit($form);

        $codes = self::getContainer()->get(BoosterCodeRepository::class)->findByBatch($batchLabel);
        $this->assertCount(1, $codes);
        $this->assertNull($codes[0]->getMaxUses());
        $this->assertNull($codes[0]->getExpiresAt());
    }

    public function testBatchSizeIsCapped(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $this->authenticateClient($client);

        $booster = $this->createBooster();
        $batchLabel = 'Trop gros ' . uniqid();

        $crawler = $client->request('GET', '/admin/booster-codes/batch');
        $form = $crawler->selectButton('Générer les codes')->form([
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '5000',
            'form[quantity]' => '1',
            'form[maxUses]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $this->assertSame([], self::getContainer()->get(BoosterCodeRepository::class)->findByBatch($batchLabel));
    }

    public function testExportReturnsTheBatchAsCsv(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $booster = $this->createBooster();
        $batchLabel = 'Export ' . uniqid();

        $code = new BoosterCode()
            ->setCode('ABCDEFGHJKLM')
            ->setBooster($booster)
            ->setBatchLabel($batchLabel)
        ;
        $entityManager->persist($code);
        $entityManager->flush();

        $client->request('GET', '/admin/booster-codes/batch/export?batch=' . urlencode($batchLabel));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');

        $csv = $client->getResponse()->getContent();
        \assert(false !== $csv);
        $this->assertStringContainsString('ABCD-EFGH-JKLM', $csv);
        $this->assertStringContainsString($batchLabel, $csv);
    }

    public function testTheBatchPageIsAdminOnly(): void
    {
        $client = self::createClient();

        $client->request('GET', '/admin/booster-codes/batch');

        $this->assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    private function createBooster(): Booster
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Code batch extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $entityManager->persist($booster);
        $entityManager->flush();

        return $booster;
    }
}
