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
 * A card a player already owns (or a drawn 1/1) can no longer go back to
 * DRAFT: not from the edit form, not from the batch action.
 */
final class AdminCardDepublicationTest extends WebTestCase
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

    public function testAnOwnedCardShowsALockedStatusWithTheReason(): void
    {
        $id = (string) $this->createCard(holders: 2)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="Card[status]"]');
        $this->assertCount(1, $select);
        $this->assertNotNull($select->attr('disabled'));
        $this->assertStringContainsString(
            '2 joueur(s) possèdent cette carte : elle ne peut plus être dépubliée.',
            $crawler->filter('form[name="Card"]')->text(),
        );
    }

    public function testATamperedEditFormLeavesAnOwnedCardPublished(): void
    {
        $id = (string) $this->createCard(holders: 1)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $values = $crawler->filter('form[name="Card"]')->form()->getPhpValues();
        $values['Card']['status'] = (string) CardStatusEnum::DRAFT->value;
        $this->client->request('POST', '/admin/card/' . $id . '/edit', $values);

        $this->assertSame(CardStatusEnum::PUBLISHED, $this->reload($id)->getStatus());
    }

    public function testTheEntityRefusesToUnpublishAnOwnedCard(): void
    {
        // reloaded: the admin edits a hydrated entity, whose original data holds the enum
        $card = $this->reload((string) $this->createCard(holders: 1)->getId())->setStatus(CardStatusEnum::DRAFT);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($card);

        $this->assertCount(1, $violations);
        $this->assertSame('status', $violations[0]->getPropertyPath());
        $this->assertSame('1 joueur(s) possèdent cette carte : elle ne peut plus être dépubliée.', $violations[0]->getMessage());
    }

    public function testTheEntityRefusesToUnpublishADrawnUnique(): void
    {
        // no UserCard row on purpose: the claimedBy alone blocks it
        $card = $this->createCard(holders: 0, claimed: true)->setStatus(CardStatusEnum::DRAFT);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($card);

        $this->assertCount(1, $violations);
        $this->assertSame(
            \sprintf('Carte unique (1/1) déjà tirée par %s : elle ne peut plus être dépubliée.', $this->user),
            $violations[0]->getMessage(),
        );
    }

    public function testAnOwnedCardAlreadyInDraftStaysEditable(): void
    {
        // legacy state (unpublished before the rule): no transition, nothing to refuse
        $card = $this->createCard(holders: 1, status: CardStatusEnum::DRAFT)->setName('Renamed legacy draft');

        $this->assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($card));
    }

    public function testAnUnownedCardCanBeUnpublishedFromTheForm(): void
    {
        $id = (string) $this->createCard(holders: 0)->getId();

        $crawler = $this->client->request('GET', '/admin/card/' . $id . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertNull($crawler->filter('select[name="Card[status]"]')->attr('disabled'));

        $form = $crawler->filter('form[name="Card"]')->form();
        $form['Card[status]'] = (string) CardStatusEnum::DRAFT->value;
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->assertSame(CardStatusEnum::DRAFT, $this->reload($id)->getStatus());
    }

    public function testTheBatchDraftRefusesOwnedCardsOneByOne(): void
    {
        $owned = $this->createCard(holders: 1);
        $claimed = $this->createCard(holders: 0, claimed: true);
        $free = $this->createCard(holders: 0);
        $ids = array_map(static fn (Card $card): string => (string) $card->getId(), [$owned, $claimed, $free]);
        $names = [$owned->getName(), $claimed->getName()];

        $this->submitDraftBatch($ids);
        self::assertResponseRedirects();
        $html = (string) $this->client->followRedirect()->html();

        $this->assertSame(CardStatusEnum::PUBLISHED, $this->reload($ids[0])->getStatus());
        $this->assertSame(CardStatusEnum::PUBLISHED, $this->reload($ids[1])->getStatus());
        $this->assertSame(CardStatusEnum::DRAFT, $this->reload($ids[2])->getStatus());
        $this->assertStringContainsString(htmlspecialchars(\sprintf('« %s » : 1 joueur(s) possèdent cette carte : elle ne peut plus être dépubliée.', $names[0])), $html);
        $this->assertStringContainsString(htmlspecialchars(\sprintf('« %s » : Carte unique (1/1) déjà tirée par', $names[1])), $html);
        $this->assertStringContainsString('1 carte repassée(s) en brouillon.', $html);
    }

    /**
     * Same flow as AdminCardBatchStatusTest: token and url read on the listing button.
     *
     * @param list<string> $ids
     */
    private function submitDraftBatch(array $ids): void
    {
        $crawler = $this->client->request('GET', '/admin/card');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('[data-action-batch="true"][data-action-url*="draft-cards"]');
        $this->assertCount(1, $button);

        $this->client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => 'draftCards',
            'entityFqcn' => Card::class,
            'batchActionUrl' => (string) $button->attr('data-action-url'),
            'batchActionCsrfToken' => (string) $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => $ids,
        ], server: ['HTTP_REFERER' => '/admin/card']);
    }

    private function createCard(int $holders, bool $claimed = false, CardStatusEnum $status = CardStatusEnum::PUBLISHED): Card
    {
        $extension = new Extension()
            ->setName('Depublication universe ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $card = new Card()
            ->setName('Depublication card ' . uniqid())
            ->setDescription('Test')
            ->setStatus($status)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        if ($claimed) {
            $holder = $this->entityManager->find(DiscordUser::class, $this->user->getDiscordId());
            $this->assertInstanceOf(DiscordUser::class, $holder);
            $card->setUnique(true)->setClaimedBy($holder);
        }
        $this->entityManager->persist($card);

        for ($i = 0; $i < $holders; ++$i) {
            $player = new DiscordUser()->setDiscordId('depublication-' . uniqid())->setUsername('Holder ' . $i);
            $this->entityManager->persist($player);
            $this->entityManager->persist(new UserCard()->setDiscordUser($player)->setCard($card)->setQuantity(1)->setHoloQuantity(0));
        }
        $this->entityManager->flush();

        return $card;
    }

    private function reload(string $id): Card
    {
        $this->entityManager->clear();
        $card = $this->entityManager->find(Card::class, $id);
        $this->assertInstanceOf(Card::class, $card);

        return $card;
    }
}
