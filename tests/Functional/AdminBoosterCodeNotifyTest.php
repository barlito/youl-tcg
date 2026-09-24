<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Announcement;
use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\Notification;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Enum\Notification\NotificationTypeEnum;
use App\Repository\BoosterCodeRepository;
use App\Service\Booster\BoosterCodeGenerator;
use App\Service\Notification\NotificationRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminBoosterCodeNotifyTest extends WebTestCase
{
    use JwtAuthTrait;

    private const string ADMIN = '188967649332428800';

    public function testSingleUseCodesForASelectionGiveEachPlayerTheirOwnCode(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $alice = $this->createPlayer('Alice');
        $bob = $this->createPlayer('Bob');
        $batchLabel = 'Perso ' . uniqid();

        $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '10', // ignored: one code per selected player
            'form[quantity]' => '2',
            'form[maxUses]' => '1',
            'form[notifyTarget][mode]' => 'selection',
            'form[notifyTarget][recipients]' => [$alice->getDiscordId(), $bob->getDiscordId()],
            'form[notifyMessage]' => 'Merci pour le tournoi',
        ]);
        self::assertResponseRedirects();

        $codes = $this->codes($batchLabel);
        $this->assertCount(2, $codes);

        $aliceNotifications = $this->codeNotificationsFor($alice);
        $bobNotifications = $this->codeNotificationsFor($bob);
        $this->assertCount(1, $aliceNotifications);
        $this->assertCount(1, $bobNotifications);

        $aliceCode = $aliceNotifications[0]->getPayload()['code'];
        $bobCode = $bobNotifications[0]->getPayload()['code'];
        $this->assertNotSame($aliceCode, $bobCode, 'Nobody receives someone else\'s code.');

        $byCode = [];
        foreach ($codes as $code) {
            $byCode[$code->getCode()] = $code->getAssignedTo()?->getDiscordId();
        }
        $this->assertSame($alice->getDiscordId(), $byCode[$aliceCode] ?? null);
        $this->assertSame($bob->getDiscordId(), $byCode[$bobCode] ?? null);

        $content = $this->renderer()->describe(NotificationTypeEnum::BOOSTER_CODE, $aliceNotifications[0]->getPayload());
        $this->assertSame('/boosters?code=' . $aliceCode, $content->link);
        $this->assertStringStartsWith('🎁 Un code booster t\'attend : 2 packs', $content->text);
        $this->assertSame('Merci pour le tournoi', $content->body);

        $announcement = $this->entityManager()->getRepository(Announcement::class)->findOneBy(['type' => NotificationTypeEnum::BOOSTER_CODE, 'context' => \sprintf('2 codes personnels · lot « %s »', $batchLabel)]);
        $this->assertNotNull($announcement);
        $this->assertSame(2, $announcement->getSentCount());
        $this->assertStringNotContainsString((string) $aliceCode, (string) $announcement->getContext());
    }

    public function testAGlobalCodeIsBroadcastWithTheSameCodeForEveryone(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $batchLabel = 'Global ' . uniqid();

        $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '1',
            'form[quantity]' => '1',
            'form[maxUses]' => '',
            'form[notifyTarget][mode]' => 'all',
        ]);
        self::assertResponseRedirects();

        $codes = $this->codes($batchLabel);
        $this->assertCount(1, $codes);

        $broadcasts = $this->broadcastsFor($codes[0]);
        $this->assertCount(1, $broadcasts);
        $this->assertSame('/boosters?code=' . $codes[0]->getCode(), $this->renderer()->describe(NotificationTypeEnum::BOOSTER_CODE, $broadcasts[0]->getPayload())->link);
        $this->assertNull($codes[0]->getAssignedTo());
    }

    public function testAMultiUseCodeForASelectionSendsTheSameCodeToEachPlayer(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $alice = $this->createPlayer('Alice');
        $bob = $this->createPlayer('Bob');
        $batchLabel = 'Partagé ' . uniqid();

        $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '1',
            'form[quantity]' => '1',
            'form[maxUses]' => '5',
            'form[notifyTarget][mode]' => 'selection',
            'form[notifyTarget][recipients]' => [$alice->getDiscordId(), $bob->getDiscordId()],
        ]);
        self::assertResponseRedirects();

        $codes = $this->codes($batchLabel);
        $this->assertCount(1, $codes);
        $this->assertSame($codes[0]->getCode(), $this->codeNotificationsFor($alice)[0]->getPayload()['code']);
        $this->assertSame($codes[0]->getCode(), $this->codeNotificationsFor($bob)[0]->getPayload()['code']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function incoherentBatches(): iterable
    {
        yield 'single-use code to every player' => [
            ['form[count]' => '1', 'form[maxUses]' => '1', 'form[notifyTarget][mode]' => 'all'],
            'Un code à usage unique ne peut pas être envoyé à tous les joueurs',
        ];
        yield 'several multi-use codes to notify' => [
            ['form[count]' => '3', 'form[maxUses]' => '10', 'form[notifyTarget][mode]' => 'all'],
            'génère un seul code',
        ];
        yield 'selection without any player' => [
            ['form[count]' => '1', 'form[maxUses]' => '1', 'form[notifyTarget][mode]' => 'selection'],
            'Choisis au moins un joueur.',
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incoherentBatches')]
    public function testIncoherentCombinationsAreRefusedBeforeGenerating(array $fields, string $error): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $batchLabel = 'Incohérent ' . uniqid();

        $crawler = $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[quantity]' => '1',
            ...$fields,
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString($error, $crawler->filter('body')->text());
        $this->assertSame([], $this->codes($batchLabel));
    }

    public function testAMultiUseCodeCannotBeSentToMorePlayersThanItsUses(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $batchLabel = 'Trop peu ' . uniqid();

        $crawler = $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '1',
            'form[quantity]' => '1',
            'form[maxUses]' => '2',
            'form[notifyTarget][mode]' => 'selection',
            'form[notifyTarget][recipients]' => [$this->createPlayer('A')->getDiscordId(), $this->createPlayer('B')->getDiscordId(), $this->createPlayer('C')->getDiscordId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('n\'a que 2 utilisations pour 3 joueurs', $crawler->filter('body')->text());
    }

    public function testGeneratingWithoutNotificationStillWorks(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $booster = $this->createBooster();
        $batchLabel = 'Silencieux ' . uniqid();

        $this->submitBatch($client, [
            'form[booster]' => (string) $booster->getId(),
            'form[batchLabel]' => $batchLabel,
            'form[count]' => '3',
            'form[quantity]' => '1',
            'form[maxUses]' => '1',
            'form[notifyTarget][mode]' => 'none',
        ]);

        self::assertResponseRedirects();
        $this->assertCount(3, $this->codes($batchLabel));
        foreach ($this->codes($batchLabel) as $code) {
            $this->assertNull($code->getAssignedTo());
        }
    }

    public function testAnExistingSingleUseCodeGoesToOnePlayerOnly(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $code = $this->createCode(maxUses: 1);
        $alice = $this->createPlayer('Alice');
        $bob = $this->createPlayer('Bob');

        // to every player: refused
        $crawler = $this->submitNotify($client, $code, ['form[target][mode]' => 'all']);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('ne s\'envoie qu\'à un seul joueur', $crawler->filter('body')->text());

        // to two players: refused
        $this->submitNotify($client, $code, ['form[target][mode]' => 'selection', 'form[target][recipients]' => [$alice->getDiscordId(), $bob->getDiscordId()]]);
        self::assertResponseStatusCodeSame(422);

        // to Alice: sent and assigned
        $this->submitNotify($client, $code, ['form[target][mode]' => 'selection', 'form[target][recipients]' => [$alice->getDiscordId()]]);
        self::assertResponseRedirects('/admin/booster-code');
        $this->assertCount(1, $this->codeNotificationsFor($alice));

        // then to Bob: refused, Alice already holds it
        $crawler = $this->submitNotify($client, $code, ['form[target][mode]' => 'selection', 'form[target][recipients]' => [$bob->getDiscordId()]]);
        self::assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('déjà été envoyé à ' . $alice->getUsername(), $crawler->filter('body')->text());
        $this->assertSame([], $this->codeNotificationsFor($bob));
        $this->assertSame([], $this->broadcastsFor($code));
    }

    public function testAnExistingGlobalCodeCanBeBroadcast(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $code = $this->createCode(maxUses: null);

        $this->submitNotify($client, $code, ['form[target][mode]' => 'all', 'form[message]' => 'Bon week-end']);

        self::assertResponseRedirects('/admin/booster-code');
        $broadcasts = $this->broadcastsFor($code);
        $this->assertCount(1, $broadcasts);
        $this->assertSame('Bon week-end', $broadcasts[0]->getPayload()['message']);
    }

    public function testARevokedCodeIsNoLongerNotifiable(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $code = $this->createCode(maxUses: null);
        $code->setDisabled(true);
        $this->entityManager()->flush();
        $url = '/admin/booster-code/' . $code->getId() . '/notify';

        $client->request('GET', $url);
        self::assertResponseRedirects('/admin/booster-code');

        // a forged POST is refused the same way
        $client->request('POST', $url, ['form' => ['target' => ['mode' => 'all']]]);
        self::assertResponseRedirects('/admin/booster-code');
        $this->assertSame([], $this->broadcastsFor($code));

        // and the listing does not offer the action on it
        $crawler = $client->request('GET', '/admin/booster-code?query=' . $code->getCode());
        $this->assertCount(1, $crawler->filter('td')->reduce(static fn (Crawler $cell): bool => str_contains($cell->text(), $code->getFormattedCode())));
        $this->assertCount(0, $crawler->filter('a[data-action-name="notifyCode"]'));
    }

    public function testTheListingOffersNotifyOnARedeemableCode(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client, self::ADMIN);
        $code = $this->createCode(maxUses: 3);

        $crawler = $client->request('GET', '/admin/booster-code?query=' . $code->getCode());

        $this->assertCount(1, $crawler->filter('a[data-action-name="notifyCode"][href*="/admin/booster-code/' . $code->getId() . '/notify"]'));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitBatch(KernelBrowser $client, array $fields): Crawler
    {
        $crawler = $client->request('GET', '/admin/booster-codes/batch');

        return $client->submit($crawler->selectButton('Générer les codes')->form($fields));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function submitNotify(KernelBrowser $client, BoosterCode $code, array $fields): Crawler
    {
        $crawler = $client->request('GET', '/admin/booster-code/' . $code->getId() . '/notify');
        self::assertResponseIsSuccessful();

        return $client->submit($crawler->selectButton('Envoyer')->form($fields));
    }

    /**
     * @return list<BoosterCode>
     */
    private function codes(string $batchLabel): array
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(BoosterCodeRepository::class)->findByBatch($batchLabel);
    }

    /**
     * @return list<Notification>
     */
    private function codeNotificationsFor(DiscordUser $user): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(Notification::class)->findBy(['type' => NotificationTypeEnum::BOOSTER_CODE, 'recipient' => $user->getDiscordId()]);
    }

    /**
     * @return list<Notification>
     */
    private function broadcastsFor(BoosterCode $code): array
    {
        $this->entityManager()->clear();
        $broadcasts = $this->entityManager()->getRepository(Notification::class)->findBy(['type' => NotificationTypeEnum::BOOSTER_CODE, 'recipient' => null]);

        return array_values(array_filter(
            $broadcasts,
            static fn (Notification $notification): bool => ($notification->getPayload()['code'] ?? null) === $code->getCode(),
        ));
    }

    private function renderer(): NotificationRenderer
    {
        return self::getContainer()->get(NotificationRenderer::class);
    }

    private function createPlayer(string $name): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId((string) random_int(10 ** 17, 10 ** 18 - 1))->setUsername($name . ' ' . uniqid());
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createBooster(): Booster
    {
        $entityManager = $this->entityManager();

        $extension = new Extension()
            ->setName('Notify extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $card = new Card()
            ->setName('Notify card')
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $entityManager->persist($card);

        $booster = new Booster()
            ->setExtension($extension)
            ->setClaimable(false)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $entityManager->persist($booster);
        $entityManager->flush();

        return $booster;
    }

    private function createCode(?int $maxUses): BoosterCode
    {
        $code = new BoosterCode()
            ->setCode(self::getContainer()->get(BoosterCodeGenerator::class)->generate())
            ->setBooster($this->createBooster())
            ->setMaxUses($maxUses)
            ->setBatchLabel('Notify ' . uniqid())
        ;
        $this->entityManager()->persist($code);
        $this->entityManager()->flush();

        return $code;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
