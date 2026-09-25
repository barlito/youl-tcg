<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\Notification\InternalLinkPolicy;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class InternalLinkValidator extends ConstraintValidator
{
    public function __construct(
        private readonly InternalLinkPolicy $internalLinkPolicy,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof InternalLink) {
            throw new UnexpectedTypeException($constraint, InternalLink::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (null === $this->internalLinkPolicy->toInternalPath($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
