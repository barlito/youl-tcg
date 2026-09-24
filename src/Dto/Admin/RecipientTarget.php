<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\DiscordUser;
use App\Enum\Notification\NotificationTargetEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Admin targeting: nobody, every player, or a hand-picked selection.
 */
final class RecipientTarget
{
    public const int MAX_SELECTION = 500;

    /**
     * @param Collection<int, DiscordUser> $recipients
     */
    public function __construct(
        public NotificationTargetEnum $mode = NotificationTargetEnum::ALL,
        public Collection $recipients = new ArrayCollection(),
    ) {
    }

    /**
     * @param list<DiscordUser> $recipients
     */
    public static function selection(array $recipients): self
    {
        return new self(NotificationTargetEnum::SELECTION, new ArrayCollection($recipients));
    }

    public static function all(): self
    {
        return new self(NotificationTargetEnum::ALL);
    }

    public function isNone(): bool
    {
        return NotificationTargetEnum::NONE === $this->mode;
    }

    public function isAll(): bool
    {
        return NotificationTargetEnum::ALL === $this->mode;
    }

    public function isSelection(): bool
    {
        return NotificationTargetEnum::SELECTION === $this->mode;
    }

    /**
     * The selected players, deduplicated; empty unless the mode is SELECTION.
     *
     * @return list<DiscordUser>
     */
    public function getSelectedRecipients(): array
    {
        if (!$this->isSelection()) {
            return [];
        }

        $unique = [];
        foreach ($this->recipients as $recipient) {
            $unique[$recipient->getDiscordId()] = $recipient;
        }

        return array_values($unique);
    }

    #[Assert\Callback]
    public function validateSelection(ExecutionContextInterface $context): void
    {
        if (!$this->isSelection()) {
            return;
        }

        $count = \count($this->getSelectedRecipients());

        if (0 === $count) {
            $context->buildViolation('Choisis au moins un joueur.')->atPath('recipients')->addViolation();
        } elseif ($count > self::MAX_SELECTION) {
            $context->buildViolation(\sprintf('Pas plus de %d joueurs par sélection : au-delà, envoie à tous.', self::MAX_SELECTION))->atPath('recipients')->addViolation();
        }
    }
}
