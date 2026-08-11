<?php

declare(strict_types=1);

namespace App\Exception\Booster;

use App\Enum\Booster\BoosterCodeRefusalEnum;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A redemption attempt that failed validation. The player-facing message is
 * the violation's own message; getReason() exposes the machine-readable cause.
 */
final class BoosterCodeRefusedException extends BoosterException
{
    public function __construct(
        string $message,
        string $userMessage,
        private readonly BoosterCodeRefusalEnum $reason,
    ) {
        parent::__construct($message, $userMessage);
    }

    /**
     * Built from the FIRST violation: the constraint raises the most specific
     * refusal and stops, so there is never a second one worth showing.
     */
    public static function fromViolations(ConstraintViolationListInterface $violations): self
    {
        $violation = $violations->get(0);
        $reason = BoosterCodeRefusalEnum::tryFrom((string) $violation->getCode()) ?? BoosterCodeRefusalEnum::INVALID_INPUT;

        return new self(
            \sprintf('Booster code redemption refused (%s).', $reason->value),
            (string) $violation->getMessage(),
            $reason,
        );
    }

    public function getReason(): BoosterCodeRefusalEnum
    {
        return $this->reason;
    }
}
