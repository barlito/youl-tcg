<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterPurchase;
use App\Enum\Booster\BoosterPurchaseStatusEnum;
use App\Repository\BoosterRepository;
use App\Tests\Support\CoinMockResponses;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class BoosterShopComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    private const string JUJU = '195659530363731968';

    private KernelBrowser $client;

    private string $boosterId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.app')->clear();
        $this->authenticateClient($this->client, self::JUJU);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (static::getContainer()->get(BoosterRepository::class)->findPublished() as $booster) {
            if (str_starts_with($booster->getExtension()->getName(), 'Cyberpunk')) {
                $booster->setPurchasable(true)->setPurchasePrice(10);
                $this->boosterId = (string) $booster->getId();
            }
        }
        $entityManager->flush();
    }

    public function testTheShopIsHiddenWithoutPurchasableBooster(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getRepository(Booster::class)->find($this->boosterId)->setPurchasable(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->assertCount(0, $this->hub()->render()->crawler()->filter('[data-testid="shop"]'));
    }

    public function testTheShopListsTheBoosterWithItsPriceAndABuyButton(): void
    {
        $crawler = $this->hub()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="shop-item"]'));
        $this->assertSame('10 YLC', trim($crawler->filter('[data-testid="shop-price"]')->text()));
        $this->assertSame('Acheter', trim($crawler->filter('[data-testid="shop-buy"]')->text()));
        $this->assertNull($crawler->filter('[data-testid="shop-buy"]')->attr('disabled'));
    }

    public function testTheBuyButtonLivesOnThePackTile(): void
    {
        $crawler = $this->hub()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="boosters-grid"] [data-testid="shop-item"]'));
    }

    public function testAPackOnSaleButNotClaimableStillShowsUpToBeBought(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getRepository(Booster::class)->find($this->boosterId)->setClaimable(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->hub()->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="boosters-grid"] [data-testid="shop-only"]'));
        $this->assertSame('Acheter', trim($crawler->filter('[data-testid="shop-buy"]')->text()));
    }

    public function testBuyingNeedsASecondConfirmingClick(): void
    {
        $hub = $this->hub();

        $crawler = $hub->call('askPurchase', ['boosterId' => $this->boosterId])->render()->crawler();
        $this->assertCount(1, $crawler->filter('[data-testid="shop-confirm"]'));
        $this->assertSame(0, $this->purchaseCount());

        $crawler = $hub->call('cancelPurchase')->render()->crawler();
        $this->assertCount(0, $crawler->filter('[data-testid="shop-confirm"]'));
    }

    public function testConfirmingBuysTheBoosterAndLocksTheShopUntilTomorrow(): void
    {
        $hub = $this->hub();
        $hub->call('askPurchase', ['boosterId' => $this->boosterId]);

        $crawler = $hub->call('confirmPurchase', ['boosterId' => $this->boosterId])->render()->crawler();

        $this->assertSame(BoosterPurchaseStatusEnum::COMPLETED, $this->onlyPurchase()->getStatus());
        $this->assertStringContainsString('ajouté à ton stock', $crawler->filter('[data-testid="purchase-success"]')->text());
        $this->assertStringContainsString('1 pack à ouvrir', $crawler->filter('[data-testid="boosters-grid"]')->text());
        $this->assertSame('Déjà acheté aujourd\'hui', trim($crawler->filter('[data-testid="shop-buy"]')->text()));
        $this->assertNotNull($crawler->filter('[data-testid="shop-buy"]')->attr('disabled'));
        $this->assertCount(1, $crawler->filter('[data-testid="shop-countdown"]'));
    }

    public function testConfirmingWithoutAskingFirstBuysNothing(): void
    {
        $crawler = $this->hub()->call('confirmPurchase', ['boosterId' => $this->boosterId])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="purchase-error"]'));
        $this->assertSame(0, $this->purchaseCount());
    }

    public function testACoinRefusalIsShownAndNothingIsCredited(): void
    {
        $this->coin()->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('{}', ['http_code' => 422]));
        $hub = $this->hub();
        $hub->call('askPurchase', ['boosterId' => $this->boosterId]);

        $crawler = $hub->call('confirmPurchase', ['boosterId' => $this->boosterId])->render()->crawler();

        $this->assertStringContainsString('Solde Youl Coin insuffisant', $crawler->filter('[data-testid="purchase-error"]')->text());
        $this->assertSame(BoosterPurchaseStatusEnum::FAILED, $this->onlyPurchase()->getStatus());
        $this->assertStringNotContainsString('1 pack à ouvrir', $crawler->filter('[data-testid="boosters-grid"]')->text());
    }

    public function testAnUncertainPaymentShowsThePendingNotice(): void
    {
        $this->coin()->override('POST', '/api/transactions', static fn (): MockResponse => new MockResponse('', ['error' => 'timeout']));
        $hub = $this->hub();
        $hub->call('askPurchase', ['boosterId' => $this->boosterId]);

        $crawler = $hub->call('confirmPurchase', ['boosterId' => $this->boosterId])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[data-testid="purchase-pending"]'));
        $this->assertSame('Paiement en cours de vérification', trim($crawler->filter('[data-testid="shop-buy"]')->text()));
        $this->assertSame(BoosterPurchaseStatusEnum::PENDING, $this->onlyPurchase()->getStatus());
    }

    public function testTheBuyButtonIsDisabledWhenTheBalanceIsTooLow(): void
    {
        $this->coin()->override('GET', '/api/user/' . self::JUJU . '/wallet', static fn (): MockResponse => CoinMockResponses::json(['id' => 'W', 'amount' => '999999999']));

        $button = $this->hub()->render()->crawler()->filter('[data-testid="shop-buy"]');

        $this->assertSame('Solde insuffisant', trim($button->text()));
        $this->assertNotNull($button->attr('disabled'));
    }

    public function testTheBuyButtonIsDisabledWhenTheCoinIsUnavailable(): void
    {
        $this->coin()->override('GET', '/api/user/' . self::JUJU . '/wallet', static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $button = $this->hub()->render()->crawler()->filter('[data-testid="shop-buy"]');

        $this->assertSame('Youl Coin indisponible', trim($button->text()));
        $this->assertNotNull($button->attr('disabled'));
    }

    private function hub(): TestLiveComponent
    {
        return $this->createLiveComponent(BoosterHub::class, client: $this->client);
    }

    private function coin(): CoinMockResponses
    {
        return static::getContainer()->get(CoinMockResponses::class);
    }

    private function purchaseCount(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(BoosterPurchase::class)->count([]);
    }

    private function onlyPurchase(): BoosterPurchase
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $purchases = $entityManager->getRepository(BoosterPurchase::class)->findAll();
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }
}
