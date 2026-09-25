<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Dto\Admin\RecipientTarget;
use App\Entity\Announcement;
use App\Entity\Booster;
use App\Entity\BoosterCode;
use App\Entity\DiscordUser;
use App\Entity\Notification;
use App\Enum\Notification\NotificationTypeEnum;
use App\Exception\Notification\NotificationRefusedException;
use App\Service\Booster\BoosterAvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Sends booster codes as notifications (link to the hub, code pre-filled).
 *
 * A single-use code only ever reaches ONE player: it is never broadcast and
 * is assigned to its recipient (BoosterCode::$assignedTo), so it cannot be
 * sent to anybody else afterwards. A multi-use code is the same for every
 * recipient, broadcast included.
 */
final readonly class BoosterCodeNotifier
{
    public function __construct(
        private NotificationService $notificationService,
        private BoosterAvailabilityService $boosterAvailability,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Why a batch about to be generated cannot be notified this way, or null.
     * Single-use + selection = one code per player (the requested count is
     * ignored); multi-use = one shared code.
     */
    public function batchRefusal(Booster $booster, ?int $maxUses, int $count, RecipientTarget $target): ?string
    {
        if ($target->isNone()) {
            return null;
        }

        if (!$this->boosterAvailability->isDistributable($booster)) {
            return 'Ce pack n\'est pas encore disponible (extension en brouillon ou sans carte publiée) : génère les codes sans notifier, et envoie-les à la publication.';
        }

        if (1 === $maxUses) {
            return $target->isAll()
                ? 'Un code à usage unique ne peut pas être envoyé à tous les joueurs : choisis une sélection (chaque joueur reçoit alors son propre code) ou un code à usages multiples.'
                : null;
        }

        if (1 !== $count) {
            return 'Pour notifier un code à usages multiples, génère un seul code : tous les destinataires reçoivent le même.';
        }

        return $this->capacityRefusal($maxUses, $target);
    }

    /**
     * Why an existing code cannot be sent to anybody, or null.
     */
    public function stateRefusal(BoosterCode $code): ?string
    {
        if (!$code->isRedeemable($this->clock->now())) {
            return 'Ce code est révoqué, expiré ou épuisé : il ne peut plus être notifié.';
        }

        if (!$this->boosterAvailability->isDistributable($code->getBooster())) {
            return 'Le pack de ce code n\'est pas encore disponible (extension en brouillon ou sans carte publiée).';
        }

        return null;
    }

    /**
     * Why an existing code cannot be sent to $target, or null.
     */
    public function codeRefusal(BoosterCode $code, RecipientTarget $target): ?string
    {
        $stateRefusal = $this->stateRefusal($code);

        if (null !== $stateRefusal) {
            return $stateRefusal;
        }

        if ($target->isNone()) {
            return 'Choisis à qui envoyer ce code.';
        }

        if (!$code->isSingleUse()) {
            return $this->capacityRefusal($code->getRemainingUses(), $target);
        }

        $recipients = $target->getSelectedRecipients();

        if ($target->isAll() || 1 !== \count($recipients)) {
            return 'Un code à usage unique ne s\'envoie qu\'à un seul joueur. Pour en notifier plusieurs, génère un lot « un code par joueur ».';
        }

        $assignedTo = $code->getAssignedTo();

        if ($assignedTo instanceof DiscordUser && $assignedTo->getDiscordId() !== $recipients[0]->getDiscordId()) {
            return \sprintf('Ce code a déjà été envoyé à %s : il ne peut pas être envoyé à un autre joueur.', $assignedTo->getUsername());
        }

        return null;
    }

    /**
     * @throws NotificationRefusedException
     */
    public function notifyCode(BoosterCode $code, RecipientTarget $target, ?string $message, ?DiscordUser $author): Announcement
    {
        $refusal = $this->codeRefusal($code, $target);

        if (null !== $refusal) {
            throw new NotificationRefusedException($refusal);
        }

        if ($code->isSingleUse()) {
            return $this->notifyPersonalCodes([[$target->getSelectedRecipients()[0], $code]], $message, $author, $code->getBatchLabel());
        }

        $message = $this->cleanMessage($message);
        $sent = 0;

        if ($target->isAll()) {
            $sent += $this->send(null, $code, $message);
        } else {
            foreach ($target->getSelectedRecipients() as $recipient) {
                $sent += $this->send($recipient, $code, $message);
            }
        }

        return $this->log($code, $message, $author, $target->getSelectedRecipients(), $sent, \sprintf('Code %s', $code->getFormattedCode()));
    }

    /**
     * One single-use code per player, each only in its owner's notification.
     *
     * @param list<array{0: DiscordUser, 1: BoosterCode}> $assignments
     *
     * @throws NotificationRefusedException
     */
    public function notifyPersonalCodes(array $assignments, ?string $message, ?DiscordUser $author, ?string $batchLabel): Announcement
    {
        if ([] === $assignments) {
            throw new NotificationRefusedException('Aucun joueur à notifier.');
        }

        $players = [];
        foreach ($assignments as [$player, $code]) {
            if (!$code->isSingleUse() || isset($players[$player->getDiscordId()])) {
                throw new \LogicException('Personal codes must be single-use, one per player.');
            }

            $players[$player->getDiscordId()] = $player;
            $code->setAssignedTo($player);
        }
        // assignments are committed before any code goes out
        $this->entityManager->flush();

        $message = $this->cleanMessage($message);
        $sent = 0;
        foreach ($assignments as [$player, $code]) {
            $sent += $this->send($player, $code, $message);
        }

        $context = \sprintf('%d code%s personnel%s', \count($assignments), \count($assignments) > 1 ? 's' : '', \count($assignments) > 1 ? 's' : '');
        if (null !== $batchLabel) {
            $context .= \sprintf(' · lot « %s »', $batchLabel);
        }

        return $this->log($assignments[0][1], $message, $author, array_values($players), $sent, $context);
    }

    /**
     * @return int 1 when the entry was stored
     */
    private function send(?DiscordUser $recipient, BoosterCode $code, ?string $message): int
    {
        if (!$recipient instanceof DiscordUser && $code->isSingleUse()) {
            throw new \LogicException('A single-use code is never broadcast.');
        }

        return $this->notificationService->notify($recipient, NotificationTypeEnum::BOOSTER_CODE, [
            'code' => $code->getCode(),
            'quantity' => $code->getQuantity(),
            'boosterName' => $code->getBooster()->getDisplayName(),
            'message' => $message,
        ]) instanceof Notification ? 1 : 0;
    }

    /**
     * @param list<DiscordUser> $recipients
     */
    private function log(BoosterCode $code, ?string $message, ?DiscordUser $author, array $recipients, int $sent, string $context): Announcement
    {
        $quantity = $code->getQuantity();
        $announcement = new Announcement(
            NotificationTypeEnum::BOOSTER_CODE,
            \sprintf('%d pack%s « %s »', $quantity, $quantity > 1 ? 's' : '', $code->getBooster()->getDisplayName()),
            $message,
            null,
            $author,
            $recipients,
            $sent,
            $this->clock->now(),
            $context,
        );
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();

        return $announcement;
    }

    private function capacityRefusal(?int $remainingUses, RecipientTarget $target): ?string
    {
        $count = \count($target->getSelectedRecipients());

        if (null !== $remainingUses && $target->isSelection() && $remainingUses < $count) {
            return \sprintf('Ce code n\'a que %d utilisation%s pour %d joueurs ciblés.', $remainingUses, $remainingUses > 1 ? 's' : '', $count);
        }

        return null;
    }

    private function cleanMessage(?string $message): ?string
    {
        $message = trim((string) $message);

        return '' !== $message ? mb_substr($message, 0, 500) : null;
    }
}
