<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\BoosterCodeRedemption;
use App\Entity\Card;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Entity\UserBooster;
use App\Enum\Entity\CardRarityEnum;
use App\Enum\Entity\CardStatusEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterCodeAlreadyRedeemedException;
use App\Exception\Booster\BoosterCodeExhaustedException;
use App\Exception\Booster\BoosterCodeNotAvailableYetException;
use App\Repository\BoosterClaimRepository;
use App\Service\Booster\BoosterCodeGenerator;
use App\Service\Booster\BoosterCodeRedeemService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoosterCodeRedeemServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BoosterCodeRedeemService $redeemService;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->redeemService = self::getContainer()->get(BoosterCodeRedeemService::class);
    }

    public function testRedeemCreditsTheBoosterInventory(): void
    {
        $scenario = $this->createScenario(quantity: 2);

        $this->redeemService->redeem($scenario['user'], $scenario['code']->getFormattedCode());

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $scenario['user'], 'booster' => $scenario['booster']])
        ;
        $this->assertNotNull($userBooster);
        $this->assertSame(2, $userBooster->getQuantity());

        $code = $this->entityManager->getRepository(BoosterCode::class)->find($scenario['code']->getId());
        $this->assertSame(1, $code?->getUses());

        $redemptions = $this->entityManager->getRepository(BoosterCodeRedemption::class)->findBy(['boosterCode' => $code]);
        $this->assertCount(1, $redemptions);
        $this->assertSame(2, $redemptions[0]->getQuantity());
    }

    public function testRedeemDoesNotConsumeTheDailyClaimQuota(): void
    {
        $scenario = $this->createScenario();

        $this->redeemService->redeem($scenario['user'], $scenario['code']->getCode());

        $claimRepository = self::getContainer()->get(BoosterClaimRepository::class);
        $this->assertSame(
            0,
            $claimRepository->countSince($scenario['user'], new \DateTimeImmutable('-1 day')),
            'A code redemption must not leave a BoosterClaim behind.',
        );
    }

    public function testACodeWorksOnANonClaimableEventBooster(): void
    {
        $scenario = $this->createScenario(claimable: false);

        $this->redeemService->redeem($scenario['user'], $scenario['code']->getCode());

        $this->entityManager->clear();

        $this->assertNotNull(
            $this->entityManager->getRepository(UserBooster::class)
                ->findOneBy(['discordUser' => $scenario['user'], 'booster' => $scenario['booster']]),
        );
    }

    public function testTheSamePlayerCannotRedeemAGlobalCodeTwice(): void
    {
        $scenario = $this->createScenario(maxUses: 10);

        $this->redeemService->redeem($scenario['user'], $scenario['code']->getCode());

        try {
            $this->redeemService->redeem($scenario['user'], $scenario['code']->getCode());
            $this->fail('Expected BoosterCodeAlreadyRedeemedException.');
        } catch (BoosterCodeAlreadyRedeemedException) {
        }

        $this->entityManager->clear();

        $userBooster = $this->entityManager->getRepository(UserBooster::class)
            ->findOneBy(['discordUser' => $scenario['user'], 'booster' => $scenario['booster']])
        ;
        $this->assertSame(1, $userBooster?->getQuantity(), 'The refused redemption must not credit anything.');

        $code = $this->entityManager->getRepository(BoosterCode::class)->find($scenario['code']->getId());
        $this->assertSame(1, $code?->getUses());
    }

    public function testAGlobalCodeStopsAtItsUseLimit(): void
    {
        $scenario = $this->createScenario(maxUses: 2);
        $players = [$scenario['user'], $this->createUser(), $this->createUser()];
        $this->entityManager->flush();

        $this->redeemService->redeem($players[0], $scenario['code']->getCode());
        $this->redeemService->redeem($players[1], $scenario['code']->getCode());

        $this->expectException(BoosterCodeExhaustedException::class);

        $this->redeemService->redeem($players[2], $scenario['code']->getCode());
    }

    public function testACodeOfAnUnpublishedExtensionKeepsItsUses(): void
    {
        $scenario = $this->createScenario(published: false);

        try {
            $this->redeemService->redeem($scenario['user'], $scenario['code']->getCode());
            $this->fail('Expected BoosterCodeNotAvailableYetException.');
        } catch (BoosterCodeNotAvailableYetException) {
        }

        $this->entityManager->clear();

        $code = $this->entityManager->getRepository(BoosterCode::class)->find($scenario['code']->getId());
        $this->assertSame(0, $code?->getUses(), 'A code handed out before release must still work on release day.');
        $this->assertSame([], $this->entityManager->getRepository(UserBooster::class)->findBy(['discordUser' => $scenario['user']]));
    }

    /**
     * @return array{user: DiscordUser, booster: Booster, code: BoosterCode}
     */
    private function createScenario(
        int $quantity = 1,
        ?int $maxUses = 1,
        bool $claimable = true,
        bool $published = true,
    ): array {
        $extension = new Extension()
            ->setName('Code test extension ' . uniqid())
            ->setDescription('Test extension')
            ->setStatus($published ? ExtensionStatusEnum::PUBLISHED : ExtensionStatusEnum::DRAFT)
        ;
        $this->entityManager->persist($extension);

        $card = new Card()
            ->setName('Code test card')
            ->setDescription('Test card')
            ->setStatus(CardStatusEnum::PUBLISHED)
            ->setRarity(CardRarityEnum::COMMON)
            ->setExtension($extension)
        ;
        $card->setImageName('default_card.png');
        $this->entityManager->persist($card);

        $booster = new Booster()
            ->setExtension($extension)
            ->setClaimable($claimable)
            ->setRarityRates([['rarities' => ['common' => 100], 'holoChance' => 0]])
        ;
        $booster->setImageName('default_card.png');
        $this->entityManager->persist($booster);

        $code = new BoosterCode()
            ->setCode(self::getContainer()->get(BoosterCodeGenerator::class)->generate())
            ->setBooster($booster)
            ->setQuantity($quantity)
            ->setMaxUses($maxUses)
        ;
        $this->entityManager->persist($code);

        $user = $this->createUser();
        $this->entityManager->flush();

        return ['user' => $user, 'booster' => $booster, 'code' => $code];
    }

    private function createUser(): DiscordUser
    {
        $user = new DiscordUser()->setDiscordId('code-' . uniqid())->setUsername('Tester');
        $this->entityManager->persist($user);

        return $user;
    }
}
