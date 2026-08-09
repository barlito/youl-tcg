<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\BoosterCodeRedemptionAttempt;
use App\Entity\BoosterCode;
use App\Enum\Booster\BoosterCodeRefusalEnum;
use App\Repository\BoosterCodeRedemptionRepository;
use App\Service\Booster\BoosterAvailabilityService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Rules are ordered by how much they tell the player: the most specific
 * refusal wins, and only the first one is raised (a code that is both expired
 * and exhausted is simply expired).
 *
 * Availability is checked last on purpose — the caller consumes a use only
 * once this validator passes, so a code handed out before a release bounces
 * without being burnt.
 */
final class RedeemableBoosterCodeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BoosterCodeRedemptionRepository $boosterCodeRedemptionRepository,
        private readonly BoosterAvailabilityService $boosterAvailability,
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RedeemableBoosterCode) {
            throw new UnexpectedTypeException($constraint, RedeemableBoosterCode::class);
        }

        if (!$value instanceof BoosterCodeRedemptionAttempt) {
            throw new UnexpectedValueException($value, BoosterCodeRedemptionAttempt::class);
        }

        $boosterCode = $value->boosterCode;

        if (!$boosterCode instanceof BoosterCode || $boosterCode->isDisabled()) {
            $this->refuse($constraint->unknownMessage, BoosterCodeRefusalEnum::UNKNOWN);

            return;
        }

        if ($boosterCode->isExpired($this->clock->now())) {
            $this->refuse($constraint->expiredMessage, BoosterCodeRefusalEnum::EXPIRED);

            return;
        }

        if ($this->boosterCodeRedemptionRepository->existsFor($boosterCode, $value->discordUser)) {
            $this->refuse($constraint->alreadyRedeemedMessage, BoosterCodeRefusalEnum::ALREADY_REDEEMED);

            return;
        }

        if ($boosterCode->isExhausted()) {
            $this->refuse($constraint->exhaustedMessage, BoosterCodeRefusalEnum::EXHAUSTED);

            return;
        }

        $booster = $boosterCode->getBooster();

        if (!$this->boosterAvailability->hasPublishedExtension($booster) || !$this->boosterAvailability->isDrawable($booster)) {
            $this->refuse($constraint->notAvailableYetMessage, BoosterCodeRefusalEnum::NOT_AVAILABLE_YET);
        }
    }

    private function refuse(string $message, BoosterCodeRefusalEnum $reason): void
    {
        $this->context->buildViolation($message)
            ->setCode($reason->value)
            ->addViolation()
        ;
    }
}
