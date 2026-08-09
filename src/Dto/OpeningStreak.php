<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A player's daily opening streak: consecutive Europe/Paris calendar days
 * with at least one booster opening. The running series either ends today
 * (openedToday) or ended yesterday — in that case today's opening is what
 * keeps it alive. A series that ended before yesterday is dead: length 0.
 */
final readonly class OpeningStreak
{
    /**
     * A reward milestone every MILESTONE_STEP consecutive days (7, 14, 21…).
     */
    public const int MILESTONE_STEP = 7;

    public function __construct(
        public int $length,
        public bool $openedToday,
        /** Paris calendar day (Y-m-d) the running series began — the series identity. */
        public ?string $seriesStartedOn = null,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->length > 0;
    }

    /**
     * A running series not fed today: it dies at midnight Paris.
     */
    public function isAtRisk(): bool
    {
        return $this->length > 0 && !$this->openedToday;
    }

    /**
     * Milestones the running series has reached, in ascending order.
     *
     * @return list<int>
     */
    public function reachedMilestones(): array
    {
        $milestones = [];

        for ($milestone = self::MILESTONE_STEP; $milestone <= $this->length; $milestone += self::MILESTONE_STEP) {
            $milestones[] = $milestone;
        }

        return $milestones;
    }

    public function nextMilestone(): int
    {
        return (intdiv($this->length, self::MILESTONE_STEP) + 1) * self::MILESTONE_STEP;
    }

    public function daysUntilNextMilestone(): int
    {
        return $this->nextMilestone() - $this->length;
    }
}
