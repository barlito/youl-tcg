<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Card;
use App\Entity\Extension;
use App\Service\Card\CardDepublicationGuard;
use App\Service\Extension\ExtensionDepublicationGuard;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NotDepublishedWhileOwnedValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CardDepublicationGuard $cardGuard,
        private readonly ExtensionDepublicationGuard $extensionGuard,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotDepublishedWhileOwned) {
            throw new UnexpectedTypeException($constraint, NotDepublishedWhileOwned::class);
        }

        $reason = match (true) {
            $value instanceof Card => $this->cardGuard->isDepublication($value) ? $this->cardGuard->blockReason($value) : null,
            $value instanceof Extension => $this->extensionGuard->isDepublication($value) ? $this->extensionGuard->blockReason($value) : null,
            default => throw new UnexpectedValueException($value, Card::class . '|' . Extension::class),
        };
        if (null !== $reason) {
            $this->context->buildViolation($reason)->atPath('status')->addViolation();
        }
    }
}
