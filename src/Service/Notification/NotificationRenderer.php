<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\NotificationContent;
use App\Dto\NotificationView;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Service\Booster\BoosterCodeGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a stored notification (type + payload) into text and an internal
 * link. Links are generated from route names, or (announcements) re-checked
 * as internal paths: a payload can never point a player outside the app.
 */
final readonly class NotificationRenderer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function render(Notification $notification, DiscordUser $viewer): NotificationView
    {
        $content = $this->describe($notification->getType(), $notification->getPayload());

        return new NotificationView(
            $notification->getId(),
            $content->icon,
            $content->text,
            $content->link,
            $notification->getCreatedAt(),
            $notification->isUnreadFor($viewer),
            $content->body,
        );
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public function describe(NotificationTypeEnum $type, array $payload): NotificationContent
    {
        return match ($type) {
            // the card itself is never named: only who and in which universe
            NotificationTypeEnum::UNIQUE_PULLED => new NotificationContent(
                '◆',
                \sprintf('%s a tiré une carte unique dans %s', $this->string($payload, 'playerName', 'Un joueur'), $this->string($payload, 'universe', 'un univers')),
                $this->playerLink($payload),
            ),
            NotificationTypeEnum::STREAK_REWARD_AVAILABLE => new NotificationContent(
                '▲',
                \sprintf('Palier de série %d jours atteint : choisis ton pack bonus', $this->int($payload, 'milestone')),
                $this->urlGenerator->generate('boosters'),
            ),
            NotificationTypeEnum::BOOSTER_CREDITED => new NotificationContent(
                '+',
                $this->boosterCreditedText($payload),
                $this->urlGenerator->generate('boosters'),
            ),
            NotificationTypeEnum::TRADE_RECEIVED => new NotificationContent(
                '⇄',
                \sprintf('%s te propose un échange', $this->string($payload, 'playerName', 'Un joueur')),
                $this->urlGenerator->generate('trades'),
            ),
            NotificationTypeEnum::TRADE_ACCEPTED => new NotificationContent(
                '✓',
                \sprintf('%s a accepté ton échange', $this->string($payload, 'playerName', 'Un joueur')),
                $this->urlGenerator->generate('trades'),
            ),
            NotificationTypeEnum::TRADE_REFUSED => new NotificationContent(
                '✕',
                \sprintf('%s a refusé ton échange', $this->string($payload, 'playerName', 'Un joueur')),
                $this->urlGenerator->generate('trades'),
            ),
            NotificationTypeEnum::ANNOUNCEMENT => new NotificationContent(
                '!',
                $this->string($payload, 'title', 'Annonce'),
                $this->announcementLink($payload),
                $this->nullableString($payload, 'message'),
            ),
            NotificationTypeEnum::BOOSTER_CODE => new NotificationContent(
                '#',
                $this->boosterCodeText($payload),
                $this->boosterCodeLink($payload),
                $this->nullableString($payload, 'message'),
            ),
        };
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function boosterCodeText(array $payload): string
    {
        $quantity = max(1, $this->int($payload, 'quantity'));

        return \sprintf(
            '🎁 Un code booster t\'attend : %d pack%s « %s »',
            $quantity,
            $quantity > 1 ? 's' : '',
            $this->string($payload, 'boosterName', 'booster'),
        );
    }

    /**
     * The hub with the code pre-filled. Re-normalized here: whatever the
     * payload holds, the query string only ever carries code characters.
     *
     * @param array<string, scalar|null> $payload
     */
    private function boosterCodeLink(array $payload): string
    {
        $code = BoosterCodeGenerator::normalize($this->string($payload, 'code', ''));

        return $this->urlGenerator->generate('boosters', '' !== $code ? ['code' => $code] : []);
    }

    /**
     * Checked again at render time: only an internal path is ever followed.
     *
     * @param array<string, scalar|null> $payload
     */
    private function announcementLink(array $payload): ?string
    {
        $link = $this->string($payload, 'link', '');

        return InternalLinkPolicy::isInternalPath($link) ? $link : null;
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
    private function nullableString(array $payload, string $key): ?string
    {
        $value = $this->string($payload, $key, '');

        return '' !== $value ? $value : null;
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
