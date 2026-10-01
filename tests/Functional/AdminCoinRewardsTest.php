<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoinSettings;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
use App\Enum\Coin\UniverseRewardStatusEnum;
use App\Repository\DiscordUserRepository;
use App\Repository\ExtensionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCoinRewardsTest extends WebTestCase
{
    use JwtAuthTrait;

    public function testTheDefaultRewardIsEditableInTheCoinSettings(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);

        $crawler = $client->request('GET', '/admin/coin-settings/1/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="CoinSettings"]')->form();
        $form['CoinSettings[defaultUniverseRewardCoins]'] = '750';
        $client->submit($form);
        self::assertResponseRedirects();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $this->assertSame(750, $entityManager->find(CoinSettings::class, 1)?->getDefaultUniverseRewardCoins());

        $client->request('GET', '/admin/coin-settings/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheUniverseRewardAmountIsAnOptionalNonNegativeInteger(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $extension = self::getContainer()->get(ExtensionRepository::class)->findOneBy([]);
        $url = '/admin/extension/' . $extension->getId() . '/edit';

        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form#edit-Extension-form')->form();
        $this->assertSame('', $form['Extension[completionRewardCoins]']->getValue());

        $form['Extension[completionRewardCoins]'] = '-5';
        $crawler = $client->submit($form);
        $this->assertStringContainsString('supérieure ou égale à zéro', $crawler->filter('body')->text());

        $form = $client->request('GET', $url)->filter('form#edit-Extension-form')->form();
        $form['Extension[completionRewardCoins]'] = '0';
        $client->submit($form);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $this->assertSame(0, $entityManager->find(Extension::class, $extension->getId())?->getCompletionRewardCoins());
    }

    public function testTheRewardsListIsReadOnlyAndFilterable(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(DiscordUserRepository::class)->find('195659530363731968');
        $extension = self::getContainer()->get(ExtensionRepository::class)->findOneBy([]);
        $reward = new UniverseCompletionReward($user, $extension, 500, new \DateTimeImmutable());
        $reward->markPaid('tx-reward-1', new \DateTimeImmutable());
        $entityManager->persist($reward);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/universe-completion-reward');
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('tx-reward-1', $crawler->filter('table')->text());

        $crawler = $client->request('GET', '/admin/universe-completion-reward', ['filters' => ['status' => ['comparison' => '=', 'value' => 'failed']]]);
        $this->assertStringNotContainsString('tx-reward-1', $crawler->filter('table')->text());

        $client->request('GET', '/admin/universe-completion-reward/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCancelBatchActionCancelsTheSelectedRewards(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(DiscordUserRepository::class)->find('195659530363731968');
        [$first, $second] = self::getContainer()->get(ExtensionRepository::class)->findBy([], ['name' => 'ASC'], 2);
        $cancelled = new UniverseCompletionReward($user, $first, 500, new \DateTimeImmutable());
        $cancelled->markFailed();
        $kept = new UniverseCompletionReward($user, $second, 500, new \DateTimeImmutable());
        $entityManager->persist($cancelled);
        $entityManager->persist($kept);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/universe-completion-reward');
        $button = $crawler->filter('[data-action-batch="true"][data-action-url*="cancel-rewards"]');
        $this->assertCount(1, $button);
        $client->request('POST', (string) $button->attr('data-action-url'), [
            'batchActionName' => 'cancelRewards',
            'entityFqcn' => UniverseCompletionReward::class,
            'batchActionUrl' => (string) $button->attr('data-action-url'),
            'batchActionCsrfToken' => (string) $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => [(string) $cancelled->getId()],
        ]);
        self::assertResponseRedirects();

        $entityManager->clear();
        $this->assertSame(UniverseRewardStatusEnum::CANCELLED, $entityManager->find(UniverseCompletionReward::class, $cancelled->getId())?->getStatus());
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $entityManager->find(UniverseCompletionReward::class, $kept->getId())?->getStatus());

        $crawler = $client->request('GET', '/admin/universe-completion-reward', ['filters' => ['status' => ['comparison' => '=', 'value' => 'cancelled']]]);
        $this->assertStringContainsString('Annulée', $crawler->filter('table')->text());
    }

    public function testTheCancelBatchActionRefusesAForgedToken(): void
    {
        $client = self::createClient();
        $this->authenticateClient($client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(DiscordUserRepository::class)->find('195659530363731968');
        $reward = new UniverseCompletionReward($user, self::getContainer()->get(ExtensionRepository::class)->findOneBy([]), 500, new \DateTimeImmutable());
        $entityManager->persist($reward);
        $entityManager->flush();

        $crawler = $client->request('GET', '/admin/universe-completion-reward');
        $url = (string) $crawler->filter('[data-action-batch="true"][data-action-url*="cancel-rewards"]')->attr('data-action-url');
        $client->request('POST', $url, [
            'batchActionName' => 'cancelRewards',
            'entityFqcn' => UniverseCompletionReward::class,
            'batchActionUrl' => $url,
            'batchActionCsrfToken' => 'forged-token',
            'batchActionEntityIds' => [(string) $reward->getId()],
        ]);

        $entityManager->clear();
        $this->assertSame(UniverseRewardStatusEnum::PENDING, $entityManager->find(UniverseCompletionReward::class, $reward->getId())?->getStatus());
    }
}
