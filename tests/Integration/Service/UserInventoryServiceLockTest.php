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
use App\Service\Booster\UserInventoryService;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real commits (no DAMA rollback): row locks are only observable from a second connection.
 */
#[SkipDatabaseRollback]
final class UserInventoryServiceLockTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private UserInventoryService $inventory;

    private Connection $otherConnection;

    private DiscordUser $user;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->inventory = self::getContainer()->get(UserInventoryService::class);
        $this->otherConnection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $this->extension = new Extension()
            ->setName('Lock test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($this->extension);

        $this->user = new DiscordUser()->setDiscordId('lock-test-' . uniqid())->setUsername('Lock tester');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->otherConnection->close();

        $connection->executeStatement('DELETE FROM user_card WHERE discord_user_id = ?', [$this->user->getDiscordId()]);
        $connection->executeStatement('DELETE FROM card WHERE extension_id = ?', [(string) $this->extension->getId()]);
        $connection->executeStatement('DELETE FROM extension WHERE id = ?', [(string) $this->extension->getId()]);
        $connection->executeStatement('DELETE FROM discord_user WHERE discord_id = ?', [$this->user->getDiscordId()]);

        parent::tearDown();
    }

    public function testExistingRowIsLockedUntilTheCallerCommits(): void
    {
        $card = $this->createCard();
        $this->creditCommitted($card);

        $this->entityManager->beginTransaction();
        $this->inventory->addCard($this->user, $card, 1);

        $this->assertRowLocked($card);

        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->assertSame(2, $this->committedQuantity($card));
    }

    public function testMissingRowIsCreatedAndLockedBeforeTheFlush(): void
    {
        $card = $this->createCard();

        $this->entityManager->beginTransaction();
        $this->inventory->addCard($this->user, $card, 1, 1);

        // a concurrent credit of the same new card must wait, not hit a PK violation at flush
        $this->otherConnection->executeStatement("SET lock_timeout = '200ms'");
        $this->assertLockNotAvailable(
            fn (): int | string => $this->otherConnection->executeStatement(
                'INSERT INTO user_card (discord_user_id, card_id, quantity, holo_quantity, created_at, updated_at) VALUES (?, ?, 1, 0, NOW(), NOW()) ON CONFLICT DO NOTHING',
                [$this->user->getDiscordId(), (string) $card->getId()],
            ),
            'The new user_card row must already be held by the crediting transaction.',
        );

        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->assertSame(1, $this->committedQuantity($card));
        $this->assertRowUnlocked($card);
    }

    public function testCreditIsAppliedOnTheCommittedValueNotAStaleEntity(): void
    {
        $card = $this->createCard();
        $this->creditCommitted($card);

        // another transaction commits a change while our entity is still managed with quantity 1
        $this->otherConnection->executeStatement(
            'UPDATE user_card SET quantity = 5, holo_quantity = 2 WHERE discord_user_id = ? AND card_id = ?',
            [$this->user->getDiscordId(), (string) $card->getId()],
        );

        $this->entityManager->beginTransaction();
        $userCard = $this->inventory->addCard($this->user, $card, 2, 1);
        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->assertSame(7, $userCard->getQuantity());
        $this->assertSame(3, $userCard->getHoloQuantity());
        $this->assertSame(7, $this->committedQuantity($card));
    }

    public function testSeveralCardsAreAllLocked(): void
    {
        $cards = [$this->createCard(), $this->createCard(), $this->createCard()];
        $this->creditCommitted($cards[0]);

        $this->entityManager->beginTransaction();
        $this->inventory->addCards($this->user, array_map(
            static fn (Card $card): array => ['card' => $card, 'quantity' => 1, 'holoQuantity' => 0],
            $cards,
        ));

        $this->assertRowLocked($cards[0]);

        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->assertSame(2, $this->committedQuantity($cards[0]));
        $this->assertSame(1, $this->committedQuantity($cards[1]));
        $this->assertSame(1, $this->committedQuantity($cards[2]));
    }

    public function testCreditOutsideATransactionIsRefused(): void
    {
        $card = $this->createCard();

        $this->expectException(\LogicException::class);

        $this->inventory->addCard($this->user, $card, 1);
    }

    private function creditCommitted(Card $card): void
    {
        $this->entityManager->wrapInTransaction(fn (): UserCard => $this->inventory->addCard($this->user, $card, 1));
    }

    private function createCard(): Card
    {
        $card = new Card()
            ->setName('Lock test card ' . uniqid())
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::DRAFT)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($this->extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function assertRowLocked(Card $card): void
    {
        $this->assertLockNotAvailable(
            fn (): int => $this->selectForUpdateNowait($card),
            'The user_card row must be locked FOR UPDATE by the crediting transaction.',
        );
    }

    private function assertLockNotAvailable(callable $query, string $message): void
    {
        try {
            $query();
        } catch (DriverException $exception) {
            $this->assertSame('55P03', $exception->getSQLState(), $exception->getMessage());

            return;
        }

        $this->fail($message);
    }

    private function assertRowUnlocked(Card $card): void
    {
        $this->otherConnection->beginTransaction();
        $this->assertSame(1, $this->selectForUpdateNowait($card));
        $this->otherConnection->rollBack();
    }

    private function selectForUpdateNowait(Card $card): int
    {
        return \count($this->otherConnection->fetchAllAssociative(
            'SELECT quantity FROM user_card WHERE discord_user_id = ? AND card_id = ? FOR UPDATE NOWAIT',
            [$this->user->getDiscordId(), (string) $card->getId()],
        ));
    }

    private function committedQuantity(Card $card): int
    {
        return (int) $this->otherConnection->fetchOne(
            'SELECT quantity FROM user_card WHERE discord_user_id = ? AND card_id = ?',
            [$this->user->getDiscordId(), (string) $card->getId()],
        );
    }
}
