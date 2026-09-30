<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Booster\BoosterPurchaseService;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real commits (no DAMA rollback): a row lock is only observable from a second connection.
 */
#[SkipDatabaseRollback]
final class BoosterPurchaseLockTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $otherConnection;

    /** @var list<object> */
    private array $entities = [];

    private DiscordUser $user;

    private Booster $booster;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->otherConnection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $extension = new Extension()->setName('Purchase lock ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $card = new Card()->setName('Lock card')->setDescription('Test')->setStatus(CardStatusEnum::PUBLISHED)->setRarity(CardRarityEnum::COMMON)->setExtension($extension);
        $card->setImageName('default_card.png');
        $this->booster = new Booster()->setExtension($extension)->setPurchasable(true)->setPurchasePrice(5)->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]]);
        $this->booster->setImageName('default_card.png');
        $this->user = new DiscordUser()->setDiscordId('purchase-lock-' . uniqid())->setUsername('Lock tester');

        $this->entities = [$extension, $card, $this->booster, $this->user];
        foreach ($this->entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->otherConnection->close();

        $connection = $this->entityManager->getConnection();
        [$extension, $card] = $this->entities;
        $connection->executeStatement('DELETE FROM booster_purchase WHERE discord_user_id = ?', [$this->user->getDiscordId()]);
        $connection->executeStatement('DELETE FROM user_booster WHERE discord_user_id = ?', [$this->user->getDiscordId()]);
        $connection->executeStatement('DELETE FROM booster WHERE id = ?', [(string) $this->booster->getId()]);
        $connection->executeStatement('DELETE FROM card WHERE id = ?', [(string) $card->getId()]);
        $connection->executeStatement('DELETE FROM extension WHERE id = ?', [(string) $extension->getId()]);
        $connection->executeStatement('DELETE FROM discord_user WHERE discord_id = ?', [$this->user->getDiscordId()]);

        parent::tearDown();
    }

    public function testThePlayerRowIsLockedBeforeTheQuotaIsChecked(): void
    {
        $this->otherConnection->beginTransaction();
        $this->otherConnection->executeQuery('SELECT discord_id FROM discord_user WHERE discord_id = ? FOR UPDATE', [$this->user->getDiscordId()]);
        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(BoosterPurchaseService::class)->purchase($this->user, $this->booster, 'jwt');
            $this->fail('A purchase must wait for the lock held on the player row.');
        } catch (DriverException $exception) {
            $this->assertStringContainsString('lock timeout', $exception->getMessage());
        } finally {
            $this->otherConnection->rollBack();
        }
    }
}
