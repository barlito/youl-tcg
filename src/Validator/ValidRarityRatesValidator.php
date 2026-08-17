<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Booster;
use App\Enum\Entity\CardRarityEnum;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidRarityRatesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRarityRates) {
            throw new UnexpectedTypeException($constraint, ValidRarityRates::class);
        }

        if (!\is_array($value)) {
            throw new UnexpectedValueException($value, 'array');
        }

        if ([] === $value) {
            $this->context->buildViolation($constraint->emptyMessage)->addViolation();

            return;
        }

        foreach (array_values($value) as $index => $slot) {
            $this->validateSlot($constraint, $index + 1, $slot);
        }
    }

    private function validateSlot(ValidRarityRates $constraint, int $slotNumber, mixed $slot): void
    {
        if (!\is_array($slot) || !\array_key_exists('rarities', $slot) || !\array_key_exists('holoChance', $slot)) {
            $this->context->buildViolation($constraint->invalidSlotMessage)
                ->setParameter('{{ slot }}', (string) $slotNumber)
                ->addViolation()
            ;

            return;
        }

        $this->validateRarities($constraint, $slotNumber, $slot['rarities']);
        $this->validateHoloChance($constraint, $slotNumber, $slot['holoChance']);
        // optional key: slots stored before the option existed read as 0
        $this->validateUniqueChance($constraint, $slotNumber, $slot['uniqueChance'] ?? 0);
    }

    private function validateRarities(ValidRarityRates $constraint, int $slotNumber, mixed $rarities): void
    {
        if (!\is_array($rarities) || [] === $rarities) {
            $this->context->buildViolation($constraint->invalidRaritiesMessage)
                ->setParameter('{{ slot }}', (string) $slotNumber)
                ->addViolation()
            ;

            return;
        }

        foreach ($rarities as $rarity => $weight) {
            if (null === CardRarityEnum::tryFrom((string) $rarity)) {
                $this->context->buildViolation($constraint->invalidRarityMessage)
                    ->setParameter('{{ slot }}', (string) $slotNumber)
                    ->setParameter('{{ rarity }}', (string) $rarity)
                    ->setParameter('{{ rarities }}', implode(', ', array_column(CardRarityEnum::cases(), 'value')))
                    ->addViolation()
                ;
            }

            if (!\is_int($weight) || $weight < 1) {
                $this->context->buildViolation($constraint->invalidWeightMessage)
                    ->setParameter('{{ slot }}', (string) $slotNumber)
                    ->setParameter('{{ rarity }}', (string) $rarity)
                    ->addViolation()
                ;
            }
        }
    }

    private function validateHoloChance(ValidRarityRates $constraint, int $slotNumber, mixed $holoChance): void
    {
        if (!\is_int($holoChance) || $holoChance < 0 || $holoChance > 100) {
            $this->context->buildViolation($constraint->invalidHoloChanceMessage)
                ->setParameter('{{ slot }}', (string) $slotNumber)
                ->addViolation()
            ;
        }
    }

    private function validateUniqueChance(ValidRarityRates $constraint, int $slotNumber, mixed $uniqueChance): void
    {
        if (!\is_int($uniqueChance) || $uniqueChance < 0 || $uniqueChance > Booster::UNIQUE_CHANCE_SCALE) {
            $this->context->buildViolation($constraint->invalidUniqueChanceMessage)
                ->setParameter('{{ slot }}', (string) $slotNumber)
                ->setParameter('{{ scale }}', (string) Booster::UNIQUE_CHANCE_SCALE)
                ->addViolation()
            ;
        }
    }
}
