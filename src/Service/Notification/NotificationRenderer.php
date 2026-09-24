<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\NotificationView;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a stored notification (type + payload) into text and an internal
 * link. Links are always generated from route names: a payload can never
 * point a player outside the app.
 */
final readonly class NotificationRenderer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function render(Notification $notification, DiscordUser $viewer): NotificationView
    {
        [$icon, $text, $link] = $this->describe($notification->getType(), $notification->getPayload());

        return new NotificationView(
            $notification->getId(),
            $icon,
            $text,
            $link,
            $notification->getCreatedAt(),
            $notification->isUnreadFor($viewer),
        );
    }

    /**
     * @param array<string, scalar|null> $payload
     *
     * @return array{0: string, 1: string, 2: string} icon, text, link
     */
    public function describe(NotificationTypeEnum $type, array $payload): array
    {
        return match ($type) {
            // the card itself is never named: only who and in which universe
            NotificationTypeEnum::UNIQUE_PULLED => [
                '◆',
                \sprintf('%s a tiré une carte unique dans %s', $this->string($payload, 'playerName', 'Un joueur'), $this->string($payload, 'universe', 'un univers')),
                $this->playerLink($payload),
            ],
            NotificationTypeEnum::STREAK_REWARD_AVAILABLE => [
                '▲',
                \sprintf('Palier de série %d jours atteint : choisis ton pack bonus', $this->int($payload, 'milestone')),
                $this->urlGenerator->generate('boosters'),
            ],
            NotificationTypeEnum::BOOSTER_CREDITED => [
                '+',
                $this->boosterCreditedText($payload),
                $this->urlGenerator->generate('boosters'),
            ],
            NotificationTypeEnum::TRADE_RECEIVED => ['⇄', 'Tu as reçu une offre d\'échange', $this->urlGenerator->generate('homepage')],
            NotificationTypeEnum::TRADE_ACCEPTED => ['⇄', 'Ton offre d\'échange a été acceptée', $this->urlGenerator->generate('homepage')],
            NotificationTypeEnum::TRADE_REFUSED => ['⇄', 'Ton offre d\'échange a été refusée', $this->urlGenerator->generate('homepage')],
            NotificationTypeEnum::ANNOUNCEMENT => ['!', $this->string($payload, 'title', 'Annonce'), $this->urlGenerator->generate('homepage')],
            NotificationTypeEnum::BOOSTER_CODE => ['#', 'Un code booster t\'attend', $this->urlGenerator->generate('boosters')],
        };
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function boosterCreditedText(array $payload): string
    {
        $quantity = max(1, $this->int($payload, 'quantity'));
        $channel = match ($payload['channel'] ?? null) {
            'code' => ' (code)',
            'streak' => ' (récompense de série)',
            'recycle' => ' (recyclage)',
            default => '',
        };

        return \sprintf(
            '%d pack%s « %s » ajouté%s à ton stock%s',
            $quantity,
            $quantity > 1 ? 's' : '',
            $this->string($payload, 'boosterName', 'booster'),
            $quantity > 1 ? 's' : '',
            $channel,
        );
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function playerLink(array $payload): string
    {
        $playerId = $this->string($payload, 'playerId', '');

        return ctype_digit($playerId)
            ? $this->urlGenerator->generate('leaderboard_player', ['discordId' => $playerId])
            : $this->urlGenerator->generate('leaderboard');
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function string(array $payload, string $key, string $default): string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }
}
