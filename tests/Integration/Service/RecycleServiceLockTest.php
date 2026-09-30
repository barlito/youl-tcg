<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\RecycleSelectionLine;
use App\Entity\Booster;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Recycle\RecycleService;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real commits (no DAMA rollback): a lock is only observable from a second connection.
 */
#[SkipDatabaseRollback]
final class RecycleServiceLockTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $otherConnection;

    private DiscordUser $user;

    private Extension $extension;

    private Booster $booster;

    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->otherConnection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $this->extension = new Extension()
            ->setName('Recycle lock extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->user = new DiscordUser()->setDiscordId('recycle-lock-' . uniqid())->setUsername('Lock tester');
        $this->card = new Card()
            ->setName('Recycle lock card ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($this->extension)
        ;
        $this->card->setImageName('default_card.png');
        $this->booster = new Booster()
            ->setExtension($this->extension)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $this->booster->setImageName('default_card.png');

        foreach ([$this->extension, $this->user, $this->card, $this->booster] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->user)->setCard($this->card)->setQuantity(12)->setHoloQuantity(0));
        $this->entityManager->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->otherConnection->close();

        $connection = $this->entityManager->getConnection();
        $userId = $this->user->getDiscordId();
        $connection->executeStatement('DELETE FROM user_card WHERE discord_user_id = ?', [$userId]);
        $connection->executeStatement('DELETE FROM booster WHERE id = ?', [(string) $this->booster->getId()]);
        $connection->executeStatement('DELETE FROM card WHERE id = ?', [(string) $this->card->getId()]);
        $connection->executeStatement('DELETE FROM extension WHERE id = ?', [(string) $this->extension->getId()]);
        $connection->executeStatement('DELETE FROM discord_user WHERE discord_id = ?', [$userId]);

        parent::tearDown();
    }

    public function testRecyclingWaitsForTheLockOnThePlayerRow(): void
    {
        $this->otherConnection->beginTransaction();
        $this->otherConnection->executeQuery('SELECT 1 FROM discord_user WHERE discord_id = ? FOR UPDATE', [$this->user->getDiscordId()]);

        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(RecycleService::class)->recycle(
                $this->user,
                [new RecycleSelectionLine($this->card, normalQuantity: 10, holoQuantity: 0)],
                $this->booster,
            );
            $this->fail('The recycling must wait on the player row lock held by the other transaction.');
        } catch (DriverException $exception) {
            $this->assertSame('55P03', $exception->getSQLState(), $exception->getMessage());
        } finally {
            $this->otherConnection->rollBack();
            $this->entityManager->getConnection()->executeStatement('SET lock_timeout = 0');
        }
    }
}
