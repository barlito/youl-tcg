<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\MarketListing;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Market\MarketListingService;
use App\Service\Market\MarketPurchaseService;
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
final class MarketPurchaseLockTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $otherConnection;

    private DiscordUser $seller;

    private DiscordUser $buyer;

    private Extension $extension;

    private Card $card;

    private MarketListing $listing;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->otherConnection = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $this->extension = new Extension()->setName('Market lock ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->card = new Card()->setName('Lock card')->setDescription('Test')->setStatus(CardStatusEnum::PUBLISHED)->setRarity(CardRarityEnum::COMMON)->setExtension($this->extension);
        $this->card->setImageName('default_card.png');
        $this->seller = new DiscordUser()->setDiscordId('market-lock-seller-' . uniqid())->setUsername('Seller');
        $this->buyer = new DiscordUser()->setDiscordId('market-lock-buyer-' . uniqid())->setUsername('Buyer');
        foreach ([$this->extension, $this->card, $this->seller, $this->buyer] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO user_card (discord_user_id, card_id, quantity, holo_quantity, created_at, updated_at) VALUES (?, ?, 1, 0, NOW(), NOW())',
            [$this->seller->getDiscordId(), (string) $this->card->getId()],
        );
        $this->listing = self::getContainer()->get(MarketListingService::class)->create($this->seller, $this->card, false, 10);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->otherConnection->close();

        $connection = $this->entityManager->getConnection();
        $users = [$this->seller->getDiscordId(), $this->buyer->getDiscordId()];
        $connection->executeStatement('DELETE FROM market_purchase WHERE seller_id = ?', [$users[0]]);
        $connection->executeStatement('DELETE FROM market_listing WHERE seller_id = ?', [$users[0]]);
        $connection->executeStatement('DELETE FROM user_card WHERE card_id = ?', [(string) $this->card->getId()]);
        $connection->executeStatement('DELETE FROM notification WHERE recipient_id IN (?, ?)', $users);
        $connection->executeStatement('DELETE FROM card WHERE id = ?', [(string) $this->card->getId()]);
        $connection->executeStatement('DELETE FROM extension WHERE id = ?', [(string) $this->extension->getId()]);
        $connection->executeStatement('DELETE FROM discord_user WHERE discord_id IN (?, ?)', $users);

        parent::tearDown();
    }

    public function testTheListingRowIsLockedBeforeItsStatusIsRead(): void
    {
        $this->otherConnection->beginTransaction();
        $this->otherConnection->executeQuery('SELECT id FROM market_listing WHERE id = ? FOR UPDATE', [(string) $this->listing->getId()]);
        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(MarketPurchaseService::class)->purchase($this->buyer, $this->listing, 'jwt');
            $this->fail('A purchase must wait for the lock held on the listing row.');
        } catch (DriverException $exception) {
            $this->assertStringContainsString('lock timeout', $exception->getMessage());
        } finally {
            $this->otherConnection->rollBack();
        }
    }

    public function testTheSellerRowIsLockedBeforeTheCopyMoves(): void
    {
        $this->otherConnection->beginTransaction();
        $this->otherConnection->executeQuery('SELECT card_id FROM user_card WHERE discord_user_id = ? AND card_id = ? FOR UPDATE', [$this->seller->getDiscordId(), (string) $this->card->getId()]);
        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(MarketPurchaseService::class)->purchase($this->buyer, $this->listing, 'jwt');
            $this->fail('The transfer must wait for the lock held on the seller inventory row.');
        } catch (DriverException $exception) {
            $this->assertStringContainsString('lock timeout', $exception->getMessage());
        } finally {
            $this->otherConnection->rollBack();
        }
    }
}
