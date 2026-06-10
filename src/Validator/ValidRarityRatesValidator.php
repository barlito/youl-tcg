<?php

declare(strict_types=1);

namespace App\Validator;

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
        if (!\is_array($slot) || [] === $slot) {
            $this->context->buildViolation($constraint->invalidSlotMessage)
                ->setParameter('{{ slot }}', (string) $slotNumber)
                ->addViolation()
            ;

            return;
        }

        foreach ($slot as $rarity => $weight) {
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
}
