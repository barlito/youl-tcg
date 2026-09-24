<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Card;
use App\Service\Card\CardDepublicationGuard;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NotDepublishedWhileOwnedValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CardDepublicationGuard $guard,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotDepublishedWhileOwned) {
            throw new UnexpectedTypeException($constraint, NotDepublishedWhileOwned::class);
        }

        if (!$value instanceof Card) {
            throw new UnexpectedValueException($value, Card::class);
        }

        if (!$this->guard->isDepublication($value)) {
            return;
        }

        $reason = $this->guard->blockReason($value);
        if (null !== $reason) {
            $this->context->buildViolation($reason)->atPath('status')->addViolation();
        }
    }
}
