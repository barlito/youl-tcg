<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterOpening;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // The homepage caches the day cards and the ticker counters: without
        // this, values leak from one test (or one local run) to the next.
        self::getContainer()->get('cache.app')->clear();
    }

    public function testHomepageShowsAllSections(): void
    {
        $this->authenticateClient($this->client);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#univers'));
        $this->assertCount(1, $crawler->filter('#raretes'));
        $this->assertCount(1, $crawler->filter('#roadmap'));
        $this->assertCount(1, $crawler->filter('[data-testid="ticker"]'));

        // The design uppercases through CSS: the DOM keeps the source case.
        $body = $crawler->filter('main')->text();
        $this->assertStringContainsString('PRESS START', $body);
        $this->assertStringContainsString('5 paliers.', $body);
        $this->assertStringContainsString('Prochain univers', $body);
    }

    public function testTickerShowsRealCounters(): void
    {
        $user = $this->authenticateClient($this->client);
        $this->createOpenings($user, 2);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $ticker = $crawler->filter('[data-testid="ticker"]')->text();
        $this->assertStringContainsString('2 packs ouverts', $ticker);
        $this->assertStringContainsString('univers', $ticker);
        $this->assertStringContainsString('2 packs / jour', $ticker);
    }

    public function testUniverseGridOnlyShowsPublishedExtensions(): void
    {
        $this->authenticateClient($this->client);

        $draftExtension = new Extension()
            ->setName('Draft universe ' . uniqid())
            ->setDescription('Should stay hidden')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($draftExtension);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $grid = $crawler->filter('#univers')->text();
        $this->assertStringContainsString('Cyberpunk 2077', $grid);
        $this->assertStringNotContainsString($draftExtension->getName(), $grid);
    }

    public function testHomepageSurvivesEmptyDayCards(): void
    {
        $this->authenticateClient($this->client);

        // Prime the cache as if no published card existed when it was built.
        $pool = self::getContainer()->get('cache.app');
        \assert($pool instanceof CacheItemPoolInterface);
        $item = $pool->getItem('daycards');
        $item->set([]);
        $pool->save($item);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    private function createOpenings(DiscordUser $user, int $count): void
    {
        $extension = new Extension()
            ->setName('Homepage test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $booster = new Booster()
            ->setExtension($extension)
            ->setHoloRate(0)
            ->setRarityRates([['common' => 100]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        for ($i = 0; $i < $count; ++$i) {
            $this->entityManager->persist(new BoosterOpening($user, $booster, $i + 1, new \DateTimeImmutable()));
        }

        $this->entityManager->flush();
    }
}
