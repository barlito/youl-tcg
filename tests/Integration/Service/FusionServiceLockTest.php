<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Fusion\FusionService;
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
final class FusionServiceLockTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $otherConnection;

    private DiscordUser $user;

    private Extension $extension;

    private Card $card;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->otherConnection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $this->extension = new Extension()
            ->setName('Fusion lock extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $this->user = new DiscordUser()->setDiscordId('fusion-lock-' . uniqid())->setUsername('Lock tester');
        $this->card = new Card()
            ->setName('Fusion lock card ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($this->extension)
        ;
        $this->card->setImageName('default_card.png');

        foreach ([$this->extension, $this->user, $this->card] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->persist(new UserCard()->setDiscordUser($this->user)->setCard($this->card)->setQuantity(10)->setHoloQuantity(0));
        $this->entityManager->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->otherConnection->close();

        $connection = $this->entityManager->getConnection();
        $userId = $this->user->getDiscordId();
        $connection->executeStatement('SET lock_timeout = 0');
        $connection->executeStatement('DELETE FROM fusion_operation WHERE discord_user_id = ?', [$userId]);
        $connection->executeStatement('DELETE FROM user_card WHERE discord_user_id = ?', [$userId]);
        $connection->executeStatement('DELETE FROM card WHERE id = ?', [(string) $this->card->getId()]);
        $connection->executeStatement('DELETE FROM extension WHERE id = ?', [(string) $this->extension->getId()]);
        $connection->executeStatement('DELETE FROM discord_user WHERE discord_id = ?', [$userId]);

        parent::tearDown();
    }

    public function testFusionWaitsForTheLockOnTheUserCardRow(): void
    {
        $this->otherConnection->beginTransaction();
        $this->otherConnection->executeQuery('SELECT 1 FROM user_card WHERE discord_user_id = ? AND card_id = ? FOR UPDATE', [$this->user->getDiscordId(), (string) $this->card->getId()]);

        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(FusionService::class)->fuse($this->user, $this->card, 1);
            $this->fail('The fusion must wait on the user_card row lock held by the other transaction.');
        } catch (DriverException $exception) {
            $this->assertSame('55P03', $exception->getSQLState(), $exception->getMessage());
        } finally {
            $this->otherConnection->rollBack();
            $this->entityManager->getConnection()->executeStatement('SET lock_timeout = 0');
        }
    }
}
