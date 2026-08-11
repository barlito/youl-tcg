<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StreakRewardRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

/**
 * A milestone reward earned by an opening streak: the player picks one
 * claimable booster as a bonus (chosenBooster stays null until then).
 *
 * A series is identified by the Paris calendar day it started on: the unique
 * (user, series_started_on, milestone) tuple makes granting idempotent — a
 * milestone is earned once per series, while a later series can earn the
 * same milestone again.
 */
#[ORM\Entity(repositoryClass: StreakRewardRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_streak_reward_milestone', columns: ['discord_user_id', 'series_started_on', 'milestone'])]
class StreakReward
{
    use IdUuidTrait;
    use TimestampableEntity;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Booster $chosenBooster = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $chosenAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'discord_id', nullable: false)]
        private DiscordUser $discordUser,
        /** Paris calendar day the rewarded series began — the series identity. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $seriesStartedOn,
        #[ORM\Column]
        private int $milestone,
        #[ORM\Column]
        private \DateTimeImmutable $awardedAt,
    ) {
    }

    public function getDiscordUser(): DiscordUser
    {
        return $this->discordUser;
    }

    public function getSeriesStartedOn(): \DateTimeImmutable
    {
        return $this->seriesStartedOn;
    }

    public function getMilestone(): int
    {
        return $this->milestone;
    }

    public function getAwardedAt(): \DateTimeImmutable
    {
        return $this->awardedAt;
    }

    public function getChosenBooster(): ?Booster
    {
        return $this->chosenBooster;
    }

    public function getChosenAt(): ?\DateTimeImmutable
    {
        return $this->chosenAt;
    }

    public function isChosen(): bool
    {
        return $this->chosenBooster instanceof Booster;
    }

    public function choose(Booster $booster, \DateTimeImmutable $chosenAt): static
    {
        $this->chosenBooster = $booster;
        $this->chosenAt = $chosenAt;

        return $this;
    }
}
