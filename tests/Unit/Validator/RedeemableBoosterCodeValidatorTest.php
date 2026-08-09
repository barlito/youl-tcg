<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Dto\BoosterCodeRedemptionAttempt;
use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Entity\Extension;
use App\Enum\Booster\BoosterCodeRefusalEnum;
use App\Enum\Entity\ExtensionStatusEnum;
use App\Repository\BoosterCodeRedemptionRepository;
use App\Repository\CardRepository;
use App\Service\Booster\BoosterAvailabilityService;
use App\Validator\RedeemableBoosterCode;
use App\Validator\RedeemableBoosterCodeValidator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<RedeemableBoosterCodeValidator>
 */
final class RedeemableBoosterCodeValidatorTest extends ConstraintValidatorTestCase
{
    private const string EXTENSION_ID = '0197c0de-0000-7000-8000-000000000001';

    private bool $alreadyRedeemed = false;

    private bool $drawable = true;

    public function testARedeemableCodeRaisesNothing(): void
    {
        $this->validator->validate($this->attempt($this->code()), new RedeemableBoosterCode());

        $this->assertNoViolation();
    }

    public function testAnUnknownCodeIsRefused(): void
    {
        $this->validator->validate($this->attempt(null), new RedeemableBoosterCode());

        $this->buildViolation('Ce code n\'existe pas ou n\'est plus valide.')
            ->setCode(BoosterCodeRefusalEnum::UNKNOWN->value)
            ->assertRaised()
        ;
    }

    public function testARevokedCodeIsRefusedLikeAnUnknownOne(): void
    {
        $this->validator->validate($this->attempt($this->code()->setDisabled(true)), new RedeemableBoosterCode());

        $this->buildViolation('Ce code n\'existe pas ou n\'est plus valide.')
            ->setCode(BoosterCodeRefusalEnum::UNKNOWN->value)
            ->assertRaised()
        ;
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $code = $this->code()->setExpiresAt(new \DateTimeImmutable('2026-08-08 11:59:59', new \DateTimeZone('UTC')));

        $this->validator->validate($this->attempt($code), new RedeemableBoosterCode());

        $this->buildViolation('Ce code a expiré.')
            ->setCode(BoosterCodeRefusalEnum::EXPIRED->value)
            ->assertRaised()
        ;
    }

    public function testACodeAlreadyRedeemedByThisPlayerIsRefused(): void
    {
        $this->alreadyRedeemed = true;

        $this->validator->validate($this->attempt($this->code()->setMaxUses(100)), new RedeemableBoosterCode());

        $this->buildViolation('Tu as déjà utilisé ce code.')
            ->setCode(BoosterCodeRefusalEnum::ALREADY_REDEEMED->value)
            ->assertRaised()
        ;
    }

    public function testAnExhaustedCodeIsRefused(): void
    {
        $code = $this->code()->setMaxUses(1);
        $code->incrementUses();

        $this->validator->validate($this->attempt($code), new RedeemableBoosterCode());

        $this->buildViolation('Ce code a déjà été utilisé au maximum.')
            ->setCode(BoosterCodeRefusalEnum::EXHAUSTED->value)
            ->assertRaised()
        ;
    }

    public function testAnUnlimitedCodeIsNeverExhausted(): void
    {
        $code = $this->code()->setMaxUses(null);
        foreach (range(1, 50) as $ignored) {
            $code->incrementUses();
        }

        $this->validator->validate($this->attempt($code), new RedeemableBoosterCode());

        $this->assertNoViolation();
    }

    public function testACodeOfAnUnpublishedExtensionIsNotAvailableYet(): void
    {
        $code = $this->code();
        $code->getBooster()->getExtension()->setStatus(ExtensionStatusEnum::DRAFT);

        $this->validator->validate($this->attempt($code), new RedeemableBoosterCode());

        $this->buildViolation('Ce code est valide, mais son pack n\'est pas encore disponible. Réessaie plus tard !')
            ->setCode(BoosterCodeRefusalEnum::NOT_AVAILABLE_YET->value)
            ->assertRaised()
        ;
    }

    public function testACodeWhoseBoosterHasNoPublishedCardIsNotAvailableYet(): void
    {
        $this->drawable = false;

        $this->validator->validate($this->attempt($this->code()), new RedeemableBoosterCode());

        $this->buildViolation('Ce code est valide, mais son pack n\'est pas encore disponible. Réessaie plus tard !')
            ->setCode(BoosterCodeRefusalEnum::NOT_AVAILABLE_YET->value)
            ->assertRaised()
        ;
    }

    public function testTheMostSpecificRefusalWins(): void
    {
        // expired AND exhausted AND unavailable: the player is told it expired
        $code = $this->code()
            ->setMaxUses(1)
            ->setExpiresAt(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')))
        ;
        $code->incrementUses();
        $code->getBooster()->getExtension()->setStatus(ExtensionStatusEnum::DRAFT);

        $this->validator->validate($this->attempt($code), new RedeemableBoosterCode());

        $this->buildViolation('Ce code a expiré.')
            ->setCode(BoosterCodeRefusalEnum::EXPIRED->value)
            ->assertRaised()
        ;
    }

    #[\Override]
    protected function createValidator(): ConstraintValidatorInterface
    {
        $redemptionRepository = $this->createStub(BoosterCodeRedemptionRepository::class);
        $redemptionRepository->method('existsFor')->willReturnCallback(fn (): bool => $this->alreadyRedeemed);

        $cardRepository = $this->createStub(CardRepository::class);
        $cardRepository->method('findExtensionIdsWithPublishedCards')
            ->willReturnCallback(fn (): array => $this->drawable ? [self::EXTENSION_ID] : [])
        ;

        return new RedeemableBoosterCodeValidator(
            $redemptionRepository,
            new BoosterAvailabilityService($cardRepository),
            new MockClock('2026-08-08 12:00:00', 'UTC'),
        );
    }

    private function attempt(?BoosterCode $boosterCode): BoosterCodeRedemptionAttempt
    {
        return new BoosterCodeRedemptionAttempt(
            'ABCDEFGHJKLM',
            new DiscordUser()->setDiscordId('188967649332428800'),
            $boosterCode,
        );
    }

    private function code(): BoosterCode
    {
        $extension = new Extension()
            ->setName('Published ext')
            ->setStatus(ExtensionStatusEnum::PUBLISHED)
        ;
        $extension->setId(self::EXTENSION_ID);

        return new BoosterCode()
            ->setCode('ABCDEFGHJKLM')
            ->setBooster(new Booster()->setExtension($extension))
        ;
    }
}
