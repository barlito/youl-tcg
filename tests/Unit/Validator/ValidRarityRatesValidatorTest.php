<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Enum\Entity\CardRarityEnum;
use App\Validator\ValidRarityRates;
use App\Validator\ValidRarityRatesValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ValidRarityRatesValidator>
 */
final class ValidRarityRatesValidatorTest extends ConstraintValidatorTestCase
{
    private ValidRarityRates $constraint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->constraint = new ValidRarityRates();
    }

    public function testValidRarityRatesRaiseNoViolation(): void
    {
        $this->validator->validate([
            ['rarities' => ['common' => 100], 'holoChance' => 0],
            ['rarities' => ['common' => 60, 'rare' => 30, 'legendary' => 10], 'holoChance' => 100],
        ], $this->constraint);

        $this->assertNoViolation();
    }

    public function testNonArrayValueIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('not-an-array', $this->constraint);
    }

    public function testEmptySlotListIsRejected(): void
    {
        $this->validator->validate([], $this->constraint);

        $this->buildViolation($this->constraint->emptyMessage)->assertRaised();
    }

    #[DataProvider('provideMalformedSlots')]
    public function testMalformedSlotIsRejected(mixed $slot): void
    {
        $this->validator->validate([
            ['rarities' => ['common' => 100], 'holoChance' => 10],
            $slot,
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidSlotMessage)
            ->setParameter('{{ slot }}', '2')
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideMalformedSlots(): iterable
    {
        yield 'not an array' => ['not-a-slot'];
        yield 'missing rarities key' => [['holoChance' => 10]];
        yield 'missing holoChance key' => [['rarities' => ['common' => 100]]];
    }

    #[DataProvider('provideInvalidRaritiesMaps')]
    public function testInvalidRaritiesMapIsRejected(mixed $rarities): void
    {
        $this->validator->validate([
            ['rarities' => $rarities, 'holoChance' => 10],
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidRaritiesMessage)
            ->setParameter('{{ slot }}', '1')
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidRaritiesMaps(): iterable
    {
        yield 'not an array' => ['common'];
        yield 'empty map' => [[]];
    }

    public function testUnknownRarityIsRejected(): void
    {
        $this->validator->validate([
            ['rarities' => ['epic' => 10], 'holoChance' => 10],
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidRarityMessage)
            ->setParameter('{{ slot }}', '1')
            ->setParameter('{{ rarity }}', 'epic')
            ->setParameter('{{ rarities }}', implode(', ', array_column(CardRarityEnum::cases(), 'value')))
            ->assertRaised()
        ;
    }

    #[DataProvider('provideInvalidWeights')]
    public function testInvalidWeightIsRejected(mixed $weight): void
    {
        $this->validator->validate([
            ['rarities' => ['common' => $weight], 'holoChance' => 10],
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidWeightMessage)
            ->setParameter('{{ slot }}', '1')
            ->setParameter('{{ rarity }}', 'common')
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidWeights(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'not an integer' => ['100'];
    }

    #[DataProvider('provideInvalidHoloChances')]
    public function testInvalidHoloChanceIsRejected(mixed $holoChance): void
    {
        $this->validator->validate([
            ['rarities' => ['common' => 100], 'holoChance' => $holoChance],
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidHoloChanceMessage)
            ->setParameter('{{ slot }}', '1')
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidHoloChances(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above one hundred' => [101];
        yield 'not an integer' => ['10'];
    }

    public function testUnknownRarityWithInvalidWeightRaisesBothViolations(): void
    {
        $this->validator->validate([
            ['rarities' => ['epic' => 0], 'holoChance' => 10],
        ], $this->constraint);

        $this->buildViolation($this->constraint->invalidRarityMessage)
            ->setParameter('{{ slot }}', '1')
            ->setParameter('{{ rarity }}', 'epic')
            ->setParameter('{{ rarities }}', implode(', ', array_column(CardRarityEnum::cases(), 'value')))
            ->buildNextViolation($this->constraint->invalidWeightMessage)
            ->setParameter('{{ slot }}', '1')
            ->setParameter('{{ rarity }}', 'epic')
            ->assertRaised()
        ;
    }

    protected function createValidator(): ValidRarityRatesValidator
    {
        return new ValidRarityRatesValidator();
    }
}
