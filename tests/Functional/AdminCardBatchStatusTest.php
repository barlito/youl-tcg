<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Card;
use App\Entity\Extension;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rejoue le flux réel des batch actions EasyAdmin : le token CSRF et l'URL
 * d'action sont lus sur le bouton du listing (data-action-*), puis POSTés
 * comme le fait le JS d'EA (batchActionName + entityFqcn + entityIds).
 */
final class AdminCardBatchStatusTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string INDEX_URL = '/admin?crudAction=index&crudControllerFqcn=App%5CController%5CAdmin%5CCardCrudController';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->authenticateClient($this->client);
    }

    public function testBatchPublishOnlyUpdatesSelectedCards(): void
    {
        [$first, $second, $untouched] = $this->createCards(CardStatusEnum::DRAFT, 3);

        $this->submitBatchAction('publishCards', [$first, $second]);

        self::assertResponseRedirects();
        $this->assertStatuses(CardStatusEnum::PUBLISHED, [$first, $second]);
        $this->assertStatuses(CardStatusEnum::DRAFT, [$untouched]);
    }

    public function testBatchDraftUpdatesPublishedCards(): void
    {
        [$card] = $this->createCards(CardStatusEnum::PUBLISHED, 1);

        $this->submitBatchAction('draftCards', [$card]);

        self::assertResponseRedirects();
        $this->assertStatuses(CardStatusEnum::DRAFT, [$card]);
    }

    public function testInvalidCsrfTokenChangesNothing(): void
    {
        [$card] = $this->createCards(CardStatusEnum::DRAFT, 1);

        $this->submitBatchAction('publishCards', [$card], csrfToken: 'forged-token');

        // même comportement que le batchDelete natif : redirection dashboard, aucun écrit
        self::assertResponseRedirects();
        $this->assertStatuses(CardStatusEnum::DRAFT, [$card]);
    }

    public function testMismatchedEntityFqcnIsRejected(): void
    {
        [$card] = $this->createCards(CardStatusEnum::DRAFT, 1);

        // le FQCN vient du POST, donc du client : viser un autre FQCN doit
        // buter sur la garde anti-mismatch (vérifiée avant même le CSRF)
        $this->submitBatchAction('publishCards', [$card], entityFqcn: Extension::class);

        self::assertResponseStatusCodeSame(400);
        $this->assertStatuses(CardStatusEnum::DRAFT, [$card]);
    }

    /**
     * @param list<Card> $cards
     */
    private function submitBatchAction(string $actionName, array $cards, ?string $csrfToken = null, ?string $entityFqcn = null): void
    {
        $crawler = $this->client->request('GET', self::INDEX_URL);
        self::assertResponseIsSuccessful();

        $button = $crawler->filter(\sprintf('[data-action-batch="true"][data-action-url*="%s"]', $actionName));
        $this->assertCount(1, $button, \sprintf('Le bouton batch "%s" doit être présent sur le listing.', $actionName));

        // le vrai token du flux : minté par ActionFactory pendant le GET,
        // porté par le bouton, lié à la session du client via son cookie
        $entityFqcn ??= Card::class;
        $csrfToken ??= (string) $button->attr('data-action-csrf-token');

        $this->client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => $actionName,
            'entityFqcn' => $entityFqcn,
            'batchActionUrl' => (string) $button->attr('data-action-url'),
            'batchActionCsrfToken' => $csrfToken,
            'batchActionEntityIds' => array_map(static fn (Card $card): string => (string) $card->getId(), $cards),
        ]);
    }

    /**
     * @return list<Card>
     */
    private function createCards(CardStatusEnum $status, int $count): array
    {
        $extension = new Extension()
            ->setName('Batch status extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $cards = [];
        for ($i = 0; $i < $count; ++$i) {
            $card = new Card()
                ->setName(\sprintf('Batch status card %d %s', $i, uniqid()))
                ->setDescription('Test')
                ->setStatus($status)
                ->setRarity(CardRarityEnum::COMMON)
                ->setExtension($extension)
            ;
            $this->entityManager->persist($card);
            $cards[] = $card;
        }
        $this->entityManager->flush();

        return $cards;
    }

    /**
     * @param list<Card> $cards
     */
    private function assertStatuses(CardStatusEnum $expected, array $cards): void
    {
        // les requêtes du client rebootent le kernel : on relit depuis la base
        // plutôt que de refresh() des instances potentiellement détachées
        $this->entityManager->clear();

        foreach ($cards as $card) {
            $fresh = $this->entityManager->find(Card::class, $card->getId());
            $this->assertInstanceOf(Card::class, $fresh);
            $this->assertSame($expected, $fresh->getStatus());
        }
    }
}
