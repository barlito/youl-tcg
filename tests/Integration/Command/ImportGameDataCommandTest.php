<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Card;
use App\Tests\DuelTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class ImportGameDataCommandTest extends KernelTestCase
{
    use DuelTestTrait;

    private EntityManagerInterface $entityManager;

    private string $directory;

    private Card $benj;

    private Card $location;

    private Card $untouched;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->directory = sys_get_temp_dir() . '/ytcg-game-data-' . uniqid();

        $extension = $this->duelExtension();
        $this->benj = $this->duelCard($extension, 'Benj « Big Boss »', tags: ['character:old']);
        $this->location = $this->duelCard($extension, 'Spatio-gare', tags: ['trait:admin-made']);
        $this->untouched = $this->duelCard($extension, 'Hors données', tags: ['character:keep']);
        $this->entityManager->flush();

        $this->write('cards/space-nomad.json', ['extension' => ['slug' => 'space-nomad'], 'cards' => [
            ['id' => strtoupper((string) $this->benj->getId()), 'name' => 'Benj', 'tags' => ['character:benj', 'universe:space-nomad', 'trait:machine']],
            ['id' => '01a101ad-0000-7000-8000-000000000000', 'name' => 'Ghost', 'tags' => []],
        ]]);
        $this->write('locations/space-nomad.json', ['locations' => [
            ['id' => (string) $this->location->getId(), 'name' => 'Spatio-gare', 'extension' => 'space-nomad', 'abilities' => []],
        ]]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testItSetsTagsAndTerrainsAndIsIdempotent(): void
    {
        $tester = $this->runImport();

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('2 card(s) updated, 0 already up to date, 1 unknown.', $this->display($tester));
        $this->assertStringContainsString('01a101ad-0000-7000-8000-000000000000 Ghost (cards/space-nomad.json)', $this->display($tester));

        $this->entityManager->clear();
        $benj = $this->reload($this->benj);
        $this->assertSame(['character:benj', 'trait:machine'], $benj->getTags(), 'universe: is implicit, never stored.');
        $this->assertFalse($benj->isTerrain());
        $location = $this->reload($this->location);
        $this->assertTrue($location->isTerrain());
        $this->assertSame(['trait:admin-made'], $location->getTags(), 'A location without tags keeps the admin ones.');
        $this->assertSame(['character:keep'], $this->reload($this->untouched)->getTags());

        $replay = $this->runImport();
        $replay->assertCommandIsSuccessful();
        $this->assertStringContainsString('0 card(s) updated, 2 already up to date, 1 unknown.', $this->display($replay));
    }

    public function testTheDryRunWritesNothing(): void
    {
        $tester = $this->runImport(dryRun: true);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('2 card(s) would change', $this->display($tester));
        $this->entityManager->clear();
        $this->assertSame(['character:old'], $this->reload($this->benj)->getTags());
        $this->assertFalse($this->reload($this->location)->isTerrain());
    }

    public function testACardListedAsBothACardAndATerrainAbortsEverything(): void
    {
        $this->write('locations/other.json', ['locations' => [['id' => (string) $this->benj->getId(), 'name' => 'Benj']]]);

        $tester = $this->runImport();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('a card is either playable or a terrain', $this->display($tester));
        $this->entityManager->clear();
        $this->assertSame(['character:old'], $this->reload($this->benj)->getTags());
    }

    public function testAnInvalidTagAbortsEverything(): void
    {
        $this->write('cards/bad.json', ['cards' => [['id' => (string) Uuid::v7(), 'tags' => ['Not A Tag']]]]);

        $tester = $this->runImport();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('invalid tag "not a tag"', $this->display($tester));
    }

    public function testAnEmptyDirectoryFails(): void
    {
        $tester = $this->runImport(directory: $this->directory . '/nowhere');

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No cards/*.json nor locations/*.json', $this->display($tester));
    }

    private function runImport(bool $dryRun = false, ?string $directory = null): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.'))->find('app:duel:import-game-data'));
        $tester->execute(['path' => $directory ?? $this->directory, '--dry-run' => $dryRun]);

        return $tester;
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $path, array $data): void
    {
        new Filesystem()->dumpFile($this->directory . '/' . $path, json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private function reload(Card $card): Card
    {
        $reloaded = $this->entityManager->find(Card::class, $card->getId());
        $this->assertInstanceOf(Card::class, $reloaded);

        return $reloaded;
    }
}
