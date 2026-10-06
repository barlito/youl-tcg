<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Dto\TradeLineRequest;
use App\Dto\TradeMatchScore;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserCard;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Trade\TradeMatchScoreService;
use App\Service\Trade\TradeOfferService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TradeMatchScoreServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private TradeMatchScoreService $service;

    private Extension $extension;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(TradeMatchScoreService::class);

        $this->extension = new Extension()->setName('Match ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::PUBLISHED);
        $this->entityManager->persist($this->extension);
        $this->entityManager->flush();
    }

    public function testScoresCountMissingCardsAndMyFreeDuplicates(): void
    {
        $me = $this->user('me');
        $bob = $this->user('bob');
        $theirs = $this->card('Theirs');
        $shared = $this->card('Shared');
        $double = $this->card('Double');
        $single = $this->card('Single');
        $this->give($bob, $theirs, 5);
        $this->give($bob, $shared, 1);
        $this->give($me, $shared, 3);
        $this->give($me, $double, 2);
        $this->give($me, $single, 1);

        $score = $this->scoreOf($this->service->scoresFor($me), $bob);

        $this->assertSame(1, $score->theyHaveForMe, 'Seule « Theirs » me manque.');
        $this->assertSame(1, $score->iHaveForThem, '« Double » : doublon qu\'il n\'a pas ; « Shared » il l\'a, « Single » n\'est pas un doublon.');
    }

    public function testEngagedCopiesDoNotCountAsFreeDuplicates(): void
    {
        $me = $this->user('me');
        $bob = $this->user('bob');
        $double = $this->card('Double');
        $wanted = $this->card('Wanted');
        $this->give($me, $double, 2);
        $this->give($bob, $wanted, 1);

        $this->assertSame(1, $this->scoreOf($this->service->scoresFor($me), $bob)->iHaveForThem);

        // one copy reserved by a pending offer: still 1 free copy of a 2-copy card -> counts
        self::getContainer()->get(TradeOfferService::class)->create($me, $bob, [new TradeLineRequest($double, 1)], [new TradeLineRequest($wanted, 1)]);
        $this->assertSame(1, $this->scoreOf($this->service->scoresFor($me), $bob)->iHaveForThem);

        // every copy reserved: nothing free left
        $this->give($bob, $this->card('Other'), 1);
        $other = $this->card('Another');
        $this->give($me, $other, 2);
        self::getContainer()->get(TradeOfferService::class)->create($me, $bob, [new TradeLineRequest($double, 1), new TradeLineRequest($other, 2)], [new TradeLineRequest($wanted, 1)]);
        $this->assertSame(0, $this->scoreOf($this->service->scoresFor($me), $bob)->iHaveForThem);
    }

    public function testUnpublishedCardsAreIgnored(): void
    {
        $me = $this->user('me');
        $bob = $this->user('bob');
        $draft = $this->card('Draft');
        $this->give($bob, $draft, 1);
        $this->give($me, $draft, 2);
        $draft->setStatus(CardStatusEnum::DRAFT);

        $hidden = new Extension()->setName('Hidden ' . uniqid())->setDescription('Test')->setStatus(ExtensionStatusEnum::DRAFT);
        $this->entityManager->persist($hidden);
        $card = $this->card('InHidden', $hidden);
        $this->give($bob, $card, 1);
        $this->entityManager->flush();

        $score = $this->scoreOf($this->service->scoresFor($me), $bob);

        $this->assertSame(0, $score->theyHaveForMe);
        $this->assertSame(0, $score->iHaveForThem);
    }

    public function testUniquesCountAndOrderIsSumThenUsername(): void
    {
        $me = $this->user('me');
        $alice = $this->user('alice');
        $zed = $this->user('zed');
        $bob = $this->user('bob');
        $unique = $this->card('Unique');
        $unique->setUnique(true);
        $this->give($bob, $unique, 1);
        $this->give($alice, $this->card('A'), 1);
        $this->give($zed, $this->card('Z'), 1);
        $this->entityManager->flush();

        $ids = array_map(static fn (TradeMatchScore $s): string => $s->player->getUsername(), $this->service->scoresFor($me));
        $mine = array_values(array_filter($ids, static fn (string $name): bool => str_starts_with($name, 'matchscore-')));

        $this->assertSame(['matchscore-alice', 'matchscore-bob', 'matchscore-zed'], $mine, 'À score égal : ordre alphabétique.');
        $this->assertSame(1, $this->scoreOf($this->service->scoresFor($me), $bob)->theyHaveForMe, 'Un 1/1 compte comme une carte.');
    }

    /**
     * @param list<TradeMatchScore> $scores
     */
    private function scoreOf(array $scores, DiscordUser $player): TradeMatchScore
    {
        foreach ($scores as $score) {
            if ($score->player->getDiscordId() === $player->getDiscordId()) {
                return $score;
            }
        }

        $this->fail('Player missing from the scores.');
    }

    private function user(string $name): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId('ms-' . uniqid())->setUsername('matchscore-' . $name);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function card(string $name, ?Extension $extension = null): Card
    {
        $card = new Card()->setName($name . ' ' . uniqid())->setDescription('Test')->setExtension($extension ?? $this->extension)->setStatus(CardStatusEnum::PUBLISHED)->setRarity(CardRarityEnum::COMMON);
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }

    private function give(DiscordUser $user, Card $card, int $quantity): void
    {
        $this->entityManager->persist(new UserCard()->setDiscordUser($user)->setCard($card)->setQuantity($quantity)->setHoloQuantity(0));
        $this->entityManager->flush();
    }
}
