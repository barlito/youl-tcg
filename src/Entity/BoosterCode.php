<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoosterCodeRepository;
use Barlito\Utils\Traits\IdUuidTrait;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Redeemable code granting boosters outside the daily claim flow (events,
 * giveaways, compensations). A "unique" code is simply a code with maxUses 1;
 * a "global" code has a higher (or null = unlimited) maxUses. Whatever the
 * quota, a player may only redeem a given code once — see BoosterCodeRedemption.
 */
#[ORM\Entity(repositoryClass: BoosterCodeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_booster_code_code', columns: ['code'])]
#[ORM\Index(columns: ['batch_label'])]
class BoosterCode implements \Stringable
{
    use IdUuidTrait;
    use TimestampableEntity;

    /**
     * Canonical form: uppercase, dash-free (see BoosterCodeGenerator). Players
     * type it in any case and with or without separators.
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $code;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Booster $booster;

    /**
     * How many copies of the booster a single redemption credits.
     */
    #[Assert\Positive]
    #[ORM\Column(options: ['default' => 1])]
    private int $quantity = 1;

    /**
     * Total redemptions allowed, all players included. Null = unlimited.
     */
    #[Assert\Positive]
    #[ORM\Column(nullable: true)]
    private ?int $maxUses = 1;

    #[ORM\Column(options: ['default' => 0])]
    private int $uses = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * Manual revocation: keeps the row (and its audit trail) but refuses any
     * further redemption.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $disabled = false;

    /**
     * Free-form label shared by every code of a generated batch, so a batch can
     * be listed, exported and revoked as a whole.
     */
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $batchLabel = null;

    public function __toString(): string
    {
        return $this->getFormattedCode();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    /**
     * Display form, grouped in fours: ABCD-EFGH-JKLM.
     */
    public function getFormattedCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }

    /**
     * Consumption at a glance for the back-office: "3 / 10", "3 / ∞".
     */
    public function getUsageLabel(): string
    {
        return \sprintf('%d / %s', $this->uses, $this->maxUses ?? '∞');
    }

    public function getBooster(): Booster
    {
        return $this->booster;
    }

    public function setBooster(Booster $booster): static
    {
        $this->booster = $booster;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): static
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUses(): int
    {
        return $this->uses;
    }

    public function incrementUses(): static
    {
        ++$this->uses;

        return $this;
    }

    public function getRemainingUses(): ?int
    {
        return null === $this->maxUses ? null : max(0, $this->maxUses - $this->uses);
    }

    public function isExhausted(): bool
    {
        return null !== $this->maxUses && $this->uses >= $this->maxUses;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Normalised to UTC: Doctrine binds datetimes without timezone conversion,
     * so a Paris-typed expiry would otherwise be compared against UTC "now"
     * and grant the code an extra hour or two.
     */
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt?->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt instanceof \DateTimeImmutable && $this->expiresAt <= $now;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function setDisabled(bool $disabled): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function getBatchLabel(): ?string
    {
        return $this->batchLabel;
    }

    public function setBatchLabel(?string $batchLabel): static
    {
        $this->batchLabel = null !== $batchLabel && '' !== trim($batchLabel) ? trim($batchLabel) : null;

        return $this;
    }

    public function isRedeemable(\DateTimeImmutable $now): bool
    {
        return !$this->disabled && !$this->isExpired($now) && !$this->isExhausted();
    }
}
