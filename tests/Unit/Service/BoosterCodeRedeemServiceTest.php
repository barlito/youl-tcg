<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Booster\BoosterCodeRefusalEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Exception\Booster\BoosterCodeRefusedException;
use App\Repository\BoosterCodeRepository;
use App\Service\Booster\BoosterCodeRedeemService;
use App\Service\Booster\UserInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The refusal rules themselves live in RedeemableBoosterCodeValidator and are
 * covered there; what matters here is the orchestration: resolve under lock,
 * refuse on violations, otherwise credit and audit.
 */
final class BoosterCodeRedeemServiceTest extends TestCase
{
    private const string CODE = 'ABCDEFGHJKLM';

    public function testRedeemCreditsTheInventoryAndAuditsTheRedemption(): void
    {
        $clock = new MockClock('2026-08-08 12:00:00', 'UTC');
        $user = $this->user();
        $boosterCode = $this->code()->setQuantity(3);

        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->once())->method('creditBooster')->with($user, $boosterCode->getBooster(), 3);

        $entityManager = $this->entityManagerMock();
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $redemption = $this->service($boosterCode, $inventory, $entityManager, clock: $clock)->redeem($user, self::CODE);

        $this->assertSame($boosterCode, $redemption->getBoosterCode());
        $this->assertSame($user, $redemption->getDiscordUser());
        $this->assertSame(3, $redemption->getQuantity());
        $this->assertEquals($clock->now(), $redemption->getRedeemedAt());
        $this->assertSame(1, $boosterCode->getUses());
    }

    public function testTheLookupUsesTheCanonicalCodeWhateverThePlayerTyped(): void
    {
        $codeRepository = $this->createMock(BoosterCodeRepository::class);
        $codeRepository->expects($this->once())->method('findOneForUpdate')->with(self::CODE)->willReturn($this->code());

        $service = new BoosterCodeRedeemService(
            $codeRepository,
            $this->createStub(UserInventoryService::class),
            $this->validatorReturning(new ConstraintViolationList()),
            $this->entityManager(),
            new MockClock('2026-08-08 12:00:00', 'UTC'),
        );

        $service->redeem($this->user(), ' abcd-efgh jklm ');
    }

    public function testBlankInputIsValidatedWithoutHittingTheDatabase(): void
    {
        $codeRepository = $this->createMock(BoosterCodeRepository::class);
        $codeRepository->expects($this->never())->method('findOneForUpdate');

        $service = new BoosterCodeRedeemService(
            $codeRepository,
            $this->createStub(UserInventoryService::class),
            $this->validatorReturning($this->violation('Saisis un code pour continuer.', null)),
            $this->entityManager(),
            new MockClock('2026-08-08 12:00:00', 'UTC'),
        );

        try {
            $service->redeem($this->user(), '  --  ');
            $this->fail('Expected BoosterCodeRefusedException.');
        } catch (BoosterCodeRefusedException $exception) {
            // a violation with no code of ours falls back to the input reason
            $this->assertSame(BoosterCodeRefusalEnum::INVALID_INPUT, $exception->getReason());
            $this->assertSame('Saisis un code pour continuer.', $exception->getUserMessage());
        }
    }

    public function testAViolationRefusesTheRedemptionWithoutCreditingAnything(): void
    {
        $boosterCode = $this->code();

        $inventory = $this->createMock(UserInventoryService::class);
        $inventory->expects($this->never())->method('creditBooster');

        $entityManager = $this->entityManagerMock();
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $service = $this->service(
            $boosterCode,
            $inventory,
            $entityManager,
            violations: $this->violation('Tu as déjà utilisé ce code.', BoosterCodeRefusalEnum::ALREADY_REDEEMED),
        );

        try {
            $service->redeem($this->user(), self::CODE);
            $this->fail('Expected BoosterCodeRefusedException.');
        } catch (BoosterCodeRefusedException $exception) {
            $this->assertSame(BoosterCodeRefusalEnum::ALREADY_REDEEMED, $exception->getReason());
            $this->assertSame('Tu as déjà utilisé ce code.', $exception->getUserMessage());
        }

        $this->assertSame(0, $boosterCode->getUses(), 'A refused attempt must not burn a use.');
    }

    private function service(
        ?BoosterCode $boosterCode = null,
        ?UserInventoryService $inventory = null,
        ?EntityManagerInterface $entityManager = null,
        ?ConstraintViolationList $violations = null,
        ?MockClock $clock = null,
    ): BoosterCodeRedeemService {
        $codeRepository = $this->createStub(BoosterCodeRepository::class);
        $codeRepository->method('findOneForUpdate')->willReturn($boosterCode);

        return new BoosterCodeRedeemService(
            $codeRepository,
            $inventory ?? $this->createStub(UserInventoryService::class),
            $this->validatorReturning($violations ?? new ConstraintViolationList()),
            $entityManager ?? $this->entityManager(),
            $clock ?? new MockClock('2026-08-08 12:00:00', 'UTC'),
        );
    }

    private function violation(string $message, ?BoosterCodeRefusalEnum $reason): ConstraintViolationList
    {
        return new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, null, null, null, $reason?->value),
        ]);
    }

    private function validatorReturning(ConstraintViolationList $violations): ValidatorInterface
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations);

        return $validator;
    }

    /**
     * @return EntityManagerInterface&MockObject
     */
    private function entityManagerMock(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function user(): DiscordUser
    {
        return new DiscordUser()->setDiscordId('188967649332428800');
    }

    private function code(): BoosterCode
    {
        return new BoosterCode()
            ->setCode(self::CODE)
            ->setBooster(new Booster()->setExtension(
                new Extension()->setName('Published ext')->setStatus(ExtensionStatusEnum::PUBLISHED),
            ))
        ;
    }
}
