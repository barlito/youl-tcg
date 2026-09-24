<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A universe whose cards players already own can no longer go back to DRAFT.
 */
final class AdminExtensionDepublicationTest extends WebTestCase
{
    use JwtAuthTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DiscordUser $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->authenticateClient($this->client);
    }

    public function testAnOwnedUniverseShowsALockedStatusWithTheReason(): void
    {
        $id = (string) $this->createExtension(holders: 2)->getId();

        $crawler = $this->client->request('GET', '/admin/extension/' . $id . '/edit');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="Extension[status]"]');
        $this->assertCount(1, $select);
        $this->assertNotNull($select->attr('disabled'));
        $this->assertStringContainsString(
            '2 joueur(s) possèdent des cartes de cet univers : il ne peut plus être dépublié.',
            $crawler->filter('form[name="Extension"]')->text(),
        );
    }

    public function testATamperedEditFormLeavesAnOwnedUniversePublished(): void
    {
        $id = (string) $this->createExtension(holders: 1)->getId();

        $crawler = $this->client->request('GET', '/admin/extension/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $values = $crawler->filter('form[name="Extension"]')->form()->getPhpValues();
        $values['Extension']['status'] = (string) ExtensionStatusEnum::DRAFT->value;
        $this->client->request('POST', '/admin/extension/' . $id . '/edit', $values);

        $this->assertSame(ExtensionStatusEnum::PUBLISHED, $this->reload($id)->getStatus());
    }

    public function testTheEntityRefusesToUnpublishAnOwnedUniverse(): void
    {
        // reloaded: the admin edits a hydrated entity, whose original data holds the enum
        $extension = $this->reload((string) $this->createExtension(holders: 1)->getId())->setStatus(ExtensionStatusEnum::DRAFT);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($extension);

        $this->assertCount(1, $violations);
        $this->assertSame('status', $violations[0]->getPropertyPath());
        $this->assertSame('1 joueur(s) possèdent des cartes de cet univers : il ne peut plus être dépublié.', $violations[0]->getMessage());
    }

    public function testADrawnUniqueBlocksTheUniverse(): void
    {
        // no UserCard row on purpose: the claimedBy alone blocks it
        $extension = $this->createExtension(holders: 0, claimedUnique: true)->setStatus(ExtensionStatusEnum::DRAFT);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($extension);

        $this->assertCount(1, $violations);
        $this->assertSame('1 joueur(s) possèdent des cartes de cet univers : il ne peut plus être dépublié.', $violations[0]->getMessage());
    }

    public function testAHolderOfSeveralCardsCountsOnce(): void
    {
        $extension = $this->createExtension(holders: 1, claimedUnique: true, uniqueHolderAlsoOwns: true)->setStatus(ExtensionStatusEnum::DRAFT);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($extension);

        $this->assertCount(1, $violations);
        $this->assertStringStartsWith('2 joueur(s)', (string) $violations[0]->getMessage());
    }

    public function testAnEmptiedHoldingDoesNotBlock(): void
    {
        $extension = $this->createExtension(holders: 1, quantity: 0)->setStatus(ExtensionStatusEnum::DRAFT);

        $this->assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($extension));
    }

    public function testAnOwnedUniverseAlreadyInDraftStaysEditable(): void
    {
        $extension = $this->createExtension(holders: 1, status: ExtensionStatusEnum::DRAFT)->setName('Renamed legacy draft ' . uniqid());

        $this->assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($extension));
    }

    public function testAnUnownedUniverseCanBeUnpublishedFromTheForm(): void
    {
        $id = (string) $this->createExtension(holders: 0)->getId();

        $crawler = $this->client->request('GET', '/admin/extension/' . $id . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertNull($crawler->filter('select[name="Extension[status]"]')->attr('disabled'));

        $form = $crawler->filter('form[name="Extension"]')->form();
        $form['Extension[status]'] = (string) ExtensionStatusEnum::DRAFT->value;
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->assertSame(ExtensionStatusEnum::DRAFT, $this->reload($id)->getStatus());
    }

    private function createExtension(
        int $holders,
        bool $claimedUnique = false,
        bool $uniqueHolderAlsoOwns = false,
        int $quantity = 1,
        ExtensionStatusEnum $status = ExtensionStatusEnum::PUBLISHED,
    ): Extension {
        $extension = new Extension()
            ->setName('Depublication universe ' . uniqid())
            ->setDescription('Test')
            ->setStatus($status)
        ;
        $this->entityManager->persist($extension);

        $card = $this->newCard($extension);
        $uniqueHolder = null;
        if ($claimedUnique) {
            $uniqueHolder = $this->entityManager->find(DiscordUser::class, $this->user->getDiscordId());
            $this->assertInstanceOf(DiscordUser::class, $uniqueHolder);
            $this->newCard($extension)->setUnique(true)->setClaimedBy($uniqueHolder);
        }

        for ($i = 0; $i < $holders; ++$i) {
            $player = new DiscordUser()->setDiscordId('ext-depublication-' . uniqid())->setUsername('Holder ' . $i);
            $this->entityManager->persist($player);
            // two cards for the same player: still one holder
            $this->entityManager->persist(new UserCard()->setDiscordUser($player)->setCard($card)->setQuantity($quantity)->setHoloQuantity(0));
            $this->entityManager->persist(new UserCard()->setDiscordUser($player)->setCard($this->newCard($extension))->setQuantity($quantity)->setHoloQuantity(0));
        }
        if ($uniqueHolder instanceof DiscordUser && $uniqueHolderAlsoOwns) {
            $this->entityManager->persist(new UserCard()->setDiscordUser($uniqueHolder)->setCard($card)->setQuantity(1)->setHoloQuantity(0));
        }
        $this->entityManager->flush();

        return $extension;
    }

    private function newCard(Extension $extension): Card
    {
        $card = new Card()
            ->setName('Depublication card ' . uniqid())
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $this->entityManager->persist($card);

        return $card;
    }

    private function reload(string $id): Extension
    {
        $this->entityManager->clear();
        $extension = $this->entityManager->find(Extension::class, $id);
        $this->assertInstanceOf(Extension::class, $extension);

        return $extension;
    }
}
