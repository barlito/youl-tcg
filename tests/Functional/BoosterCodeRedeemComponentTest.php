<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\Card;
use App\Entity\Extension;
use App\Entity\UserBooster;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Service\Booster\BoosterCodeGenerator;
use App\Twig\Components\BoosterHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BoosterCodeRedeemComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use JwtAuthTrait;

    // Juju starts without any UserBooster, unlike the default fixture user.
    private const string USER_WITHOUT_INVENTORY = '195659530363731968';

    /**
     * The attempt budget is cached on disk, like in production: without this
     * reset it would carry over from one test — and one run — to the next.
     */
    #[\Override]
    protected function setUp(): void
    {
        static::bootKernel();
        static::getContainer()->get('test.limiter.booster_code_redeem')
            ->create(self::USER_WITHOUT_INVENTORY)
            ->reset()
        ;
        static::ensureKernelShutdown();
    }

    public function testTheHubOffersACodeField(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $rendered = (string) $this->createLiveComponent(BoosterHub::class, client: $client)->render();

        $this->assertStringContainsString('J\'ai un code', $rendered);
        $this->assertStringContainsString('data-testid="code-input"', $rendered);
    }

    public function testRedeemingACodeCreditsTheInventory(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        [$booster, $code] = $this->createCode(quantity: 2);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('code', $code->getFormattedCode())->call('redeemCode');

        $userBooster = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $user, 'booster' => $booster])
        ;

        $this->assertNotNull($userBooster);
        $this->assertSame(2, $userBooster->getQuantity());
        $this->assertNull($component->component()->codeError);
        $this->assertStringContainsString('2 packs', (string) $component->component()->codeSuccess);
        $this->assertSame('', $component->component()->code, 'The field is emptied after a successful redemption.');
    }

    public function testALowercaseCodeWithoutSeparatorsWorks(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        [$booster, $code] = $this->createCode();

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('code', mb_strtolower($code->getCode()))->call('redeemCode');

        $this->assertNull($component->component()->codeError);
        $this->assertNotNull(
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(UserBooster::class)
                ->findOneBy(['discordUser' => $user, 'booster' => $booster]),
        );
    }

    public function testAnUnknownCodeIsReportedWithoutCreditingAnything(): void
    {
        $client = static::createClient();
        $user = $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('code', 'ZZZZ-ZZZZ-ZZZZ')->call('redeemCode');

        $this->assertSame('Ce code n\'existe pas ou n\'est plus valide.', $component->component()->codeError);
        $this->assertNull($component->component()->codeSuccess);
        $this->assertSame(
            [],
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(UserBooster::class)
                ->findBy(['discordUser' => $user]),
        );
    }

    public function testTheSameCodeCannotBeRedeemedTwice(): void
    {
        $client = static::createClient();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);
        [, $code] = $this->createCode(maxUses: 5);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);
        $component->set('code', $code->getCode())->call('redeemCode');
        $component->set('code', $code->getCode())->call('redeemCode');

        $this->assertSame('Tu as déjà utilisé ce code.', $component->component()->codeError);
    }

    public function testAttemptsAreRateLimited(): void
    {
        $client = static::createClient();
        // one kernel for the whole test: the limiter counters live in memory
        $client->disableReboot();
        $this->authenticateClient($client, self::USER_WITHOUT_INVENTORY);

        $component = $this->createLiveComponent(BoosterHub::class, client: $client);

        foreach (range(1, 10) as $attempt) {
            $component->set('code', \sprintf('ZZZZ-ZZZZ-Z%03d', $attempt))->call('redeemCode');
            $this->assertSame('Ce code n\'existe pas ou n\'est plus valide.', $component->component()->codeError);
        }

        $component->set('code', 'ZZZZ-ZZZZ-ZZZZ')->call('redeemCode');

        $this->assertStringStartsWith('Trop de tentatives.', (string) $component->component()->codeError);
    }

    /**
     * @return array{Booster, BoosterCode}
     */
    private function createCode(int $quantity = 1, int $maxUses = 1): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $extension = new Extension()
            ->setName('Hub code extension ' . uniqid())
            ->setDescription('Test')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $entityManager->persist($extension);

        $card = new Card()
            ->setName('Hub code card')
            ->setDescription('Test')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $entityManager->persist($card);

        // event pack: not claimable on the hub, only reachable through a code
        $booster = new Booster()
            ->setExtension($extension)
            ->setClaimable(false)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $entityManager->persist($booster);

        $code = new BoosterCode()
            ->setCode(static::getContainer()->get(BoosterCodeGenerator::class)->generate())
            ->setBooster($booster)
            ->setQuantity($quantity)
            ->setMaxUses($maxUses)
            ->setBatchLabel('Hub test')
        ;
        $entityManager->persist($code);
        $entityManager->flush();

        return [$booster, $code];
    }
}
