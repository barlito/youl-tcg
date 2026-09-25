<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Validator\InternalLink;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin announcement form data. Plain text only: it is escaped wherever it
 * is displayed, never rendered as HTML.
 */
final class AnnouncementDraft
{
    public const int TITLE_MAX = 120;

    public const int MESSAGE_MAX = 1000;

    #[Assert\NotBlank(message: 'Le titre est requis.')]
    #[Assert\Length(max: self::TITLE_MAX)]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'Le message est requis.')]
    #[Assert\Length(max: self::MESSAGE_MAX)]
    public ?string $message = null;

    #[Assert\Length(max: 255)]
    #[InternalLink]
    public ?string $link = null;

    #[Assert\Valid]
    public RecipientTarget $target;

    public function __construct()
    {
        $this->target = RecipientTarget::all();
    }
}
