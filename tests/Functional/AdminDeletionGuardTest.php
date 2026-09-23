<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\StreakReward;
use App\Entity\UserBooster;
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

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->followRedirects();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);
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

    public function testAnOwnedBoosterSurvivesAndExplainsWhy(): void
    {
        $booster = $this->createBooster();
        $this->giveBooster($booster, 2);
        $id = (string) $booster->getId();

        $crawler = $this->submitDelete('/admin/booster/' . $id);

        self::assertResponseIsSuccessful();
        $this->assertBoosterSurvived($id);
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('1 joueur(s) en possèdent encore', $body);
        $this->assertStringContainsString('non récupérable', $body);
    }

    public function testEmptyInventoryRowsDoNotBlockTheDeletion(): void
    {
        // a player who opened every copy keeps a quantity=0 row: it holds nothing
        $booster = $this->createBooster();
        $this->giveBooster($booster, 0);
        $id = (string) $booster->getId();

        $this->submitDelete('/admin/booster/' . $id);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $this->assertNull($this->entityManager->find(Booster::class, $id));
        $this->assertSame(0, $this->entityManager->getRepository(UserBooster::class)->count(['booster' => $id]));
    }

    public function testABoosterWithCodesSurvivesAndExplainsWhy(): void
    {
        $booster = $this->createBooster();
        $this->createCode($booster);
        $id = (string) $booster->getId();

        $crawler = $this->submitDelete('/admin/booster/' . $id);

        self::assertResponseIsSuccessful();
        $this->assertBoosterSurvived($id);
        $this->assertStringContainsString('1 code(s) le distribuent', $crawler->filter('body')->text());
    }

    public function testABoosterChosenAsStreakRewardSurvivesAndExplainsWhy(): void
    {
        $booster = $this->createBooster();
        $reward = new StreakReward($this->user, new \DateTimeImmutable('-7 days'), 7, new \DateTimeImmutable());
        $reward->choose($booster, new \DateTimeImmutable());
        $this->entityManager->persist($reward);
        $this->entityManager->flush();
        $id = (string) $booster->getId();

        $crawler = $this->submitDelete('/admin/booster/' . $id);

        self::assertResponseIsSuccessful();
        $this->assertBoosterSurvived($id);
        $this->assertStringContainsString('1 récompense(s) de streak l\'ont attribué', $crawler->filter('body')->text());
    }

    public function testABatchDeletesWhatItCanAndExplainsTheRest(): void
    {
        $free = $this->createBooster();
        $owned = $this->createBooster();
        $this->giveBooster($owned, 1);
        $coded = $this->createBooster();
        $this->createCode($coded);
        $ids = array_map(static fn (Booster $booster): string => (string) $booster->getId(), [$free, $owned, $coded]);

        $crawler = $this->client->request('GET', '/admin/booster');
        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-action-batch="true"][data-action-url*="batch-delete"]');
        $this->assertCount(1, $button);

        $crawler = $this->client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => 'batchDelete',
            'entityFqcn' => Booster::class,
            'batchActionUrl' => (string) $button->attr('data-action-url'),
            'batchActionCsrfToken' => (string) $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => $ids,
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $this->assertNull($this->entityManager->find(Booster::class, $ids[0]));
        $this->assertBoosterSurvived($ids[1]);
        $this->assertBoosterSurvived($ids[2]);
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('joueur(s) en possèdent encore', $body);
        $this->assertStringContainsString('code(s) le distribuent', $body);
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

    private function assertBoosterSurvived(string $id): void
    {
        $this->entityManager->clear();
        $this->assertInstanceOf(Booster::class, $this->entityManager->find(Booster::class, $id));
    }

    private function giveBooster(Booster $booster, int $quantity): void
    {
        $user = $this->entityManager->find(DiscordUser::class, $this->user->getDiscordId());
        $this->assertInstanceOf(DiscordUser::class, $user);
        $this->entityManager->persist(new UserBooster()->setDiscordUser($user)->setBooster($booster)->setQuantity($quantity));
        $this->entityManager->flush();
    }

    private function createCode(Booster $booster): void
    {
        $this->entityManager->persist(new BoosterCode()->setCode(strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)))->setBooster($booster));
        $this->entityManager->flush();
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
