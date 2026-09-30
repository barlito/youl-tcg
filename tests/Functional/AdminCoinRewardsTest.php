<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoinSettings;
use App\Entity\Extension;
use App\Entity\UniverseCompletionReward;
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
}
